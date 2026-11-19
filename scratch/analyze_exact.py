from PIL import Image

r1 = Image.open("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/crop_receipt1_exact.png")
r2 = Image.open("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/crop_receipt2_exact.png")

print("R1 size:", r1.size)
print("R2 size:", r2.size)

# Create a side-by-side comparison with labels:
# Image 1: Settings Live Preview
# Image 2: POS Modal Receipt
w = max(r1.width, r2.width)
h = max(r1.height, r2.height)

canvas = Image.new("RGB", (w * 2 + 60, h + 80), (255, 255, 255))
canvas.paste(r1, (20, 40))
canvas.paste(r2, (w + 40, 40))
canvas.save("C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/receipt_side_by_side_diff.png")
print("Saved receipt_side_by_side_diff.png!")
