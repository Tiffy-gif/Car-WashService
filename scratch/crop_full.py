from PIL import Image
import os

brain_dir = r"C:\Users\PANHA\.gemini\antigravity\brain\e6c79286-b5e2-4292-9f67-cfecf09eb003"
im1 = Image.open(os.path.join(brain_dir, ".user_uploaded", "media_1794987359308_55e5b121.jpg"))
im2 = Image.open(os.path.join(brain_dir, ".user_uploaded", "media_1794987363956_6ff9f862.jpg"))

print("im1 size:", im1.size)
print("im2 size:", im2.size)

# In im1, live preview is on the right
# Let's crop x: 810 to 995, y: 80 to 450
crop1 = im1.crop((810, 80, 995, 450))
crop1.save(os.path.join(brain_dir, "crop_full_preview_im1.png"))

# In im2, modal receipt is in the center
# Let's crop x: 410 to 590, y: 120 to 440
crop2 = im2.crop((410, 120, 590, 440))
crop2.save(os.path.join(brain_dir, "crop_full_pos_modal_im2.png"))

print("Full crops saved!")
