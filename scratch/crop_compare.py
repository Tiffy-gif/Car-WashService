from PIL import Image
import os

brain_dir = r"C:\Users\PANHA\.gemini\antigravity\brain\e6c79286-b5e2-4292-9f67-cfecf09eb003"
im1_path = os.path.join(brain_dir, ".user_uploaded", "media_1794987359308_55e5b121.jpg")
im2_path = os.path.join(brain_dir, ".user_uploaded", "media_1794987363956_6ff9f862.jpg")

im1 = Image.open(im1_path)
im2 = Image.open(im2_path)

print("Image 1 size:", im1.size)
print("Image 2 size:", im2.size)

# Crop receipt in Image 1 (Live preview on right edge)
# Image 1 is 1024x512 approx or whatever size
w1, h1 = im1.size
# Preview is roughly on right ~80% to 98% x, and 15% to 85% y
crop1 = im1.crop((int(w1 * 0.80), int(h1 * 0.15), int(w1 * 0.99), int(h1 * 0.85)))
crop1.save(os.path.join(brain_dir, "crop_preview_img1.png"))

# Crop receipt in Image 2 (POS modal in center)
w2, h2 = im2.size
# Receipt in modal is roughly center 40% to 60% x, and 20% to 75% y
crop2 = im2.crop((int(w2 * 0.38), int(h2 * 0.22), int(w2 * 0.60), int(h2 * 0.78)))
crop2.save(os.path.join(brain_dir, "crop_pos_receipt_img2.png"))

print("Cropped successfully!")
