import glob

for f in glob.glob('public/**/*', recursive=True) + glob.glob('uploads/**/*', recursive=True):
    if any(ext in f.lower() for ext in ['.png', '.jpg', '.jpeg', '.svg', '.webp']):
        print(f)
