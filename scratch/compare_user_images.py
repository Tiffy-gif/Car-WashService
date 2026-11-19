from PIL import Image

im1 = Image.open("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/crop_preview_img1.png")
im2 = Image.open("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/crop_pos_receipt_img2.png")

# Let's resize im1 and im2 to same width and paste side-by-side
target_w = 400
im1_resized = im1.resize((target_w, int(im1.height * (target_w / im1.width))), Image.LANCZOS)
im2_resized = im2.resize((target_w, int(im2.height * (target_w / im2.width))), Image.LANCZOS)

max_h = max(im1_resized.height, im2_resized.height)
comp = Image.new("RGB", (target_w * 2 + 40, max_h + 40), (15, 23, 42))

comp.paste(im1_resized, (20, 20))
comp.paste(im2_resized, (target_w + 20, 20))

comp.save("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/user_two_images_comparison.png")
print("Saved user_two_images_comparison.png!")
