import glob

for f in glob.glob('views/**/*', recursive=True):
    if 'receipt' in f.lower():
        print(f)
