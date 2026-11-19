from PIL import Image
import os

brain_dir = r"C:\Users\PANHA\.gemini\antigravity\brain\e6c79286-b5e2-4292-9f67-cfecf09eb003"
im1 = Image.open(os.path.join(brain_dir, ".user_uploaded", "media_1794987359308_55e5b121.jpg"))
im2 = Image.open(os.path.join(brain_dir, ".user_uploaded", "media_1794987363956_6ff9f862.jpg"))

# Let's precisely crop receipt 1 (settings preview):
# The settings preview receipt paper is white with dashed lines
# Let's find its exact bounding box in Image 1
# Image 1: 1024 x 528
# Coordinates of the white receipt paper in image 1:
# x approx 830 to 995, y approx 170 to 420
crop1 = im1.crop((836, 170, 990, 420))
crop1.save(os.path.join(brain_dir, "crop_receipt1_exact.png"))

# Coordinates of the white receipt paper in image 2:
# Image 2: 1024 x 552
# Modal receipt is x approx 422 to 582, y approx 140 to 430
crop2 = im2.crop((422, 140, 582, 430))
crop2.save(os.path.join(brain_dir, "crop_receipt2_exact.png"))

print("Exact crops saved!")
