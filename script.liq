settings.log.stdout.set(true)
settings.log.level.set(3)
# settings.init.allow_root(true)

icecastHost = getenv("ICECAST_HOST")
icecastPort = int_of_string(getenv("ICECAST_PORT"))
icecastAdminPass = getenv("ICECAST_ADMIN_PASSWORD")

cuistomApiBaseUrl = getenv("CUSTOM_API_BASE_URL")

uri = "http://localhost:8001/api/v1/music/select"

def get_next_track() =
    request.create(uri, persistent=false)
end

def show_metadata(m) =
  title = m["title"]
  artist = m["artist"]
  musicLink = m["x-music-link"]
  coverLink = m["x-cover-link"]
  print("Now playing: #{title} by #{artist}")
  print("Trak Link : #{musicLink}")
  print("Cover Link : #{coverLink}")
end

# A function applied to each metadata chunk
def add_meta(m) =
  [
    ("x-music-link","http://localhost:8000/api/v1/music/7826739823932873"), 
    ("x-cover-link", "http://localhost:8000/api/v1/music/cover/7826739823932873")
  ]
end

# AutoDj 
autodj = cue_cut(audio_to_stereo(request.dynamic(id="autoplaylist", get_next_track)))

# Ajouter une source de secours
#backup = blank()
safe_stream = fallback(track_sensitive=false, [autodj, single("./music/werock.mp3")])
safe_stream = metadata.map(add_meta, safe_stream)
safe_stream.on_metadata(show_metadata)

output.icecast(
  #%mp3.vbr(quality=3, samplerate=44100, stereo=true, internal_quality=3),
  %mp3(bitrate=256, samplerate=44100, stereo=true, internal_quality=3),
  host=icecastHost,
  port=icecastPort,
  name="Liquidsoap test",
  description="Liquidsoap test description",
  password=icecastAdminPass,
  mount="station.mp3",
  icy_metadata="true",
  safe_stream
)