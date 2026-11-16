Poné acá los videos de demo (H.264 .mp4, idealmente 720p):

- perimetro_norte.mp4
- perimetro_sur.mp4

Sirven clips nocturnos de personas cruzando un alambrado o un muro. Por ejemplo, los
datasets públicos VIRAT o UCF-Crime, o grabaciones propias con las cámaras de prueba.
Si un video no está en H.264, convertilo así:
  ffmpeg -i in.mov -c:v libx264 -an -vf scale=1280:720 perimetro_norte.mp4
