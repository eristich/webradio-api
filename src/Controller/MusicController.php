<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Mime\MimeTypesInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\MusicRepository;
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
    ): JsonResponse {
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
        // ini_set("xdebug.var_display_max_children", -1);
        // ini_set("xdebug.var_display_max_data", -1);
        // ini_set("xdebug.var_display_max_depth", -1);
        $getID3 = new getID3();
        $audio = $getID3->analyze($uploadAudioDir.'/'.$filename);
        // unlink($uploadAudioDir.'/'.$filename);
        // dd($audio['id3v2']);
        // exit();
        // todo: handle error if no metadata found
        $musicName = $audio['tags']['id3v2']['title'][0] ?? 'Unknown Name';
        $musicArtist = $audio['tags']['id3v2']['artist'][0] ?? 'Unknown Artist';
        $musicCoverMimetype = $audio['comments']['picture'][0]['image_mime'] ?? null;
        $musicCoverData = $audio['comments']['picture'][0]['data'] ?? null;

        // Sauvegarder la pochette d'album
        $hasCover = false;
        $musicCoverFilename = null;
        if ($musicCoverMimetype !== null) {
            // Générer un nom de fichier unique pour la pochette d'album
            $musicCoverFilename = $fileId.'-cover.'.$mimeTypes->getExtensions($musicCoverMimetype)[0];

            // Sauvegarder la pochette d'album dans le dossier d'upload
            if (file_put_contents($uploadAudioDir.'/'.$musicCoverFilename, $musicCoverData) !== false and $musicCoverMimetype !== null) {
                $hasCover = true;
            }
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

    #[Route('/api/v1/music/{musicId<\d+>}', name: 'api.v1.music.get-one', methods: ['GET'])]
    public function getOneMusic(
        #[MapEntity(id: 'musicId')]    Music    $musicId,
        MusicRepository                         $musicRepository,
        NormalizerInterface                     $normalizer
    ): JsonResponse
    {
        $music = $musicRepository->find($musicId);

        return $this->json($normalizer->normalize(
            $music, null, ['groups' => ['music:get-one']]
        ), Response::HTTP_OK);
    }

    #[Route('/api/v1/music', name: 'api.v1.music.get-all', methods: ['GET'])]
    public function getAllMusic(
        MusicRepository                         $musicRepository,
        NormalizerInterface                     $normalizer,
        Request                                 $request
    ): JsonResponse
    {
        $limit = max(min($request->query->getInt('limit', 10), 1), 20);
        $limit = (1 <= $limit) && ($limit <= 30) ? $limit : 20;
        $page = min($request->query->getInt('page', 1), 1);
        $offset = ($page - 1) * $limit;
        $order = strtoupper($request->query->getString('order', 'DESC'));
        $order = in_array($order, ['DESC', 'ASC', 'desc', 'asc']) ? $order : 'DESC';

        // dd($limit, $page, $offset);

        $music = $musicRepository->findBy(
            ['owner' => $this->getUser()],
            ['id' => $order],
            $limit,
            $offset
        );

        return $this->json($normalizer->normalize(
            $music, null, ['groups' => ['music:get-one']]
        ), Response::HTTP_OK);
    }

    #[Route('/api/v1/music/{musicId<\d+>}', name: 'api.v1.music.delete', methods: ['DELETE'])]
    public function deleteMusic(
        #[MapEntity(id: 'musicId')]    Music    $musicId,
        EntityManagerInterface                  $entityManager
    ): JsonResponse
    {
        if ($musicId->getOwner() !== $this->getUser()) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entityManager->remove($musicId);
        $entityManager->flush();

        return $this->json(['success' => 'Music deleted'], Response::HTTP_OK);
    }

    #[Route('/api/v1/music/select', name: 'api.v1.music.select-random', methods: ['GET'])]
    public function selectRandomMusic(
        MusicRepository                         $musicRepository,
        NormalizerInterface                     $normalizer
    ): Response
    {
        // find all music from the database, denormalize, and make random selection
        /** @var Music[] $musicList */
        $musicList = $musicRepository->findAll();
        $randomMusic = $musicList[array_rand($musicList)];

        $response = new StreamedResponse(function () use ($randomMusic) {
            // Open the file in read mode
            $fileStream = fopen($randomMusic->getStoragePath(), 'r');
    
            // Output the file content in chunks
            while (!feof($fileStream)) {
                echo fread($fileStream, 1024); // Adjust chunk size as needed
                flush();
            }
    
            fclose($fileStream);
        });

        // Set the response headers
        $response->headers->set('Content-Type', 'audio/mpeg');
        $response->headers->set('Content-Disposition', 'inline; filename="'.$randomMusic->getOriginalFilename().'"');

        return $response;
    }
}
