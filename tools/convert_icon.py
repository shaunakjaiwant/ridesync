import os
from PIL import Image

src_png = r"c:\xampp\htdocs\ridesync\logo-mark.png"
dst_ico = r"c:\xampp\htdocs\ridesync\RideSync.ico"

img = Image.open(src_png)
img.save(dst_ico, format='ICO', sizes=[(16,16), (32,32), (48,48), (64,64), (128,128), (256,256)])
print(f"Created ICO file at {dst_ico}")
