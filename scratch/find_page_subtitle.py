import glob

for fpath in glob.glob('views/**/*.php', recursive=True):
    with open(fpath, 'r', encoding='utf-8', errors='ignore') as f:
        c = f.read()
        if 'pageSubtitle' in c or 'topbar' in c:
            print(fpath)
