from PIL import Image

im1 = Image.open("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/crop_preview_img1.png")
im2 = Image.open("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/crop_pos_receipt_img2.png")

# Let's save higher resolution crops and inspect
im1.save("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/detail_preview_1.png")
im2.save("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/detail_pos_2.png")
print("Saved detail crops!")
