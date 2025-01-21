<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\MimeTypesInterface;
use App\Entity\Music;
use getID3;

class MusicController extends AbstractController
{

    // make route api to add music
    #[Route('/api/v1/music/upload', name: 'api.v1.music.upload', methods: ['POST'])]
    public function addMusic(
        Request                 $request,
        ValidatorInterface      $validator,
        MimeTypesInterface      $mimeTypes,
        EntityManagerInterface  $entityManager,
        #[Autowire('%kernel.project_dir%/var/uploads')] string $uploadAudioDir, 
    ): Response {
        // Récupérer le fichier de la requête
        /** @var UploadedFile $file */
        $file = $request->files->get('file');

        // Si aucun fichier n'est fourni, retourner une erreur
        if (!$file) {
            return $this->json(['error' => 'No file provided'], Response::HTTP_BAD_REQUEST);
        }

        // verifier que le fichier soit bien valide (audio MP3)
        $errors = $validator->validate($file, [
            new File([
                'maxSize' => '15M', // Limite de taille
                'mimeTypes' => [
                    'audio/mpeg',       // MP3
                    // 'audio/wav',        // WAV
                    // 'audio/x-wav',      // WAV (alternative)
                    // 'audio/ogg',        // OGG
                ],
                'mimeTypesMessage' => 'Please upload a valid audio file (MP3, WAV, OGG)',
            ])
        ]);

        // Si le fichier n'est pas valide, retourner une erreur
        if (count($errors) > 0) {
            return $this->json(['error' => $errors[0]->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // Générer un nom de fichier unique et le déplacer dans le dossier d'upload
        $fileId = uniqid().uniqid();
        $filename = $fileId.'.'.$file->guessExtension();
        $file->move($uploadAudioDir, $filename);

        // Analyser le fichier audio pour récupérer les métadonnées
        ini_set("xdebug.var_display_max_children", -1);
        ini_set("xdebug.var_display_max_data", -1);
        ini_set("xdebug.var_display_max_depth", -1);
        $getID3 = new getID3();
        $audio = $getID3->analyze($uploadAudioDir.'/'.$filename);
        var_dump($audio);
        exit();
        // todo: handle error if no metadata found
        $musicName = $audio['tags']['id3v2']['title'][0] ?? $audio['tags']['id3v2']['title'][0];
        $musicArtist = $audio['tags']['id3v2']['artist'][0] ?? $audio['tags']['id3v2']['artist'][0];
        $musicCoverMimetype = $audio['comments']['picture'][0]['image_mime'];
        $musicCoverData = $audio['comments']['picture'][0]['data'];

        // Générer un nom de fichier unique pour la pochette d'album
        $musicCoverFilename = $fileId.'-cover.'.$mimeTypes->getExtensions($musicCoverMimetype)[0];
        $hasCover = false;

        // Sauvegarder la pochette d'album dans le dossier d'upload
        if (file_put_contents($uploadAudioDir.'/'.$musicCoverFilename, $musicCoverData) !== false and $musicCoverMimetype !== null) {
            $hasCover = true;
        }

        // Créer une nouvelle entité Music
        $music = new Music();
        $music->setName($musicName);
        $music->setOriginalFilename($file->getClientOriginalName());
        $music->setArtist($musicArtist);
        $music->setStoragePath($uploadAudioDir.'/'.$filename);
        $music->setCoverPath($hasCover ? $uploadAudioDir.'/'.$musicCoverFilename : null);
        $music->setOwner($this->getUser());

        // Sauvegarder l'entité Music en base de données
        $entityManager->persist($music);
        $entityManager->flush();

        return $this->json(['success' => 'File uploaded'], Response::HTTP_OK);
    }
}
