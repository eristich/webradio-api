#!/usr/bin/liquidsoap

print("Hello, world from liquidsoap test!\n")

# url = "https://icecast.radiofrance.fr/fip-hifi.aac"
# input = mksafe(input.http(url))

stream = input.http("https://live.radio-campus.org:8002/caen.mp3", timeout=4.0)
# input = fallback(playlist("https://live.radio-campus.org:8002/caen.mp3"), single("/music/Sims2000.mp3"))
# Ajouter une source de secours pour éviter l'erreur de source fallible
# backup = blank()
safe_stream = fallback(track_sensitive=false, [stream, single("/music/Sims2000.mp3")])

output.icecast(
    %mp3(bitrate=128, samplerate=44100),
    host="icecast", 
    port=8000,
    name="Liquidsoap test",
    description="Liquidsoap test description",
    password="hackme",
    mount = "test.mp3",
    safe_stream
)