with open('public/css/style.css', 'r', encoding='utf-8') as f:
    text = f.read()

# 1. In :root, add scrollbar variables
root_target = "  --font-family: 'Kantumruy Pro', 'Inter', 'Noto Sans Khmer', system-ui, -apple-system, sans-serif;"
root_replacement = """  --font-family: 'Kantumruy Pro', 'Inter', 'Noto Sans Khmer', system-ui, -apple-system, sans-serif;

  /* Transparent Modern Scrollbars */
  --scrollbar-track: transparent;
  --scrollbar-thumb: rgba(148, 163, 184, 0.35);
  --scrollbar-thumb-hover: rgba(100, 116, 139, 0.55);"""

if root_target in text:
    text = text.replace(root_target, root_replacement)
    print("Added scrollbar variables to :root")
else:
    print("Warning: root_target not found")

# 2. In [data-theme="dark"], add scrollbar variables
dark_target = "  --info-bg: rgba(2, 132, 199, 0.2);"
dark_replacement = """  --info-bg: rgba(2, 132, 199, 0.2);

  /* Transparent Scrollbars (Dark Mode) */
  --scrollbar-track: transparent;
  --scrollbar-thumb: rgba(255, 255, 255, 0.22);
  --scrollbar-thumb-hover: rgba(255, 255, 255, 0.38);"""

if dark_target in text:
    text = text.replace(dark_target, dark_replacement)
    print("Added scrollbar variables to [data-theme='dark']")
else:
    print("Warning: dark_target not found")

# 3. Add global transparent scrollbar styles right after /* Base Reset */
base_target = "/* Base Reset */"
base_replacement = """/* ============================================================
   Global Transparent Modern Scrollbars (Clean, Minimal, Sleek)
   100% Transparent track, slim floating rounded thumb
   ============================================================ */
html, body, * {
  scrollbar-width: thin;
  scrollbar-color: var(--scrollbar-thumb) transparent;
}

/* WebKit / Blink / Edge / Chrome / Safari */
::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}

::-webkit-scrollbar-track {
  background: transparent !important;
}

::-webkit-scrollbar-thumb {
  background-color: var(--scrollbar-thumb);
  border-radius: 9999px;
  border: 1px solid transparent;
  background-clip: padding-box;
  transition: background-color 0.2s ease;
}

::-webkit-scrollbar-thumb:hover {
  background-color: var(--scrollbar-thumb-hover);
}

::-webkit-scrollbar-corner {
  background: transparent !important;
}

/* Dedicated rules for scrollable containers (Modal, Modal Body, Tables, Nav, Tabs) */
.modal,
.modal-body,
.table-responsive,
.nav-links,
.tab-pane,
.tab-content,
textarea,
.main-content {
  scrollbar-width: thin;
  scrollbar-color: var(--scrollbar-thumb) transparent;
}

.modal::-webkit-scrollbar,
.modal-body::-webkit-scrollbar,
.table-responsive::-webkit-scrollbar,
.nav-links::-webkit-scrollbar,
.tab-pane::-webkit-scrollbar,
.tab-content::-webkit-scrollbar,
textarea::-webkit-scrollbar,
.main-content::-webkit-scrollbar {
  width: 6px;
  height: 6px;
}

.modal::-webkit-scrollbar-track,
.modal-body::-webkit-scrollbar-track,
.table-responsive::-webkit-scrollbar-track,
.nav-links::-webkit-scrollbar-track,
.tab-pane::-webkit-scrollbar-track,
.tab-content::-webkit-scrollbar-track,
textarea::-webkit-scrollbar-track,
.main-content::-webkit-scrollbar-track {
  background: transparent !important;
}

.modal::-webkit-scrollbar-thumb,
.modal-body::-webkit-scrollbar-thumb,
.table-responsive::-webkit-scrollbar-thumb,
.nav-links::-webkit-scrollbar-thumb,
.tab-pane::-webkit-scrollbar-thumb,
.tab-content::-webkit-scrollbar-thumb,
textarea::-webkit-scrollbar-thumb,
.main-content::-webkit-scrollbar-thumb {
  background-color: var(--scrollbar-thumb);
  border-radius: 9999px;
}

.modal::-webkit-scrollbar-thumb:hover,
.modal-body::-webkit-scrollbar-thumb:hover,
.table-responsive::-webkit-scrollbar-thumb:hover,
.nav-links::-webkit-scrollbar-thumb:hover,
.tab-pane::-webkit-scrollbar-thumb:hover,
.tab-content::-webkit-scrollbar-thumb:hover,
textarea::-webkit-scrollbar-thumb:hover,
.main-content::-webkit-scrollbar-thumb:hover {
  background-color: var(--scrollbar-thumb-hover);
}

/* Base Reset */"""

if base_target in text:
    text = text.replace(base_target, base_replacement, 1)
    print("Added global transparent scrollbar rules")
else:
    print("Warning: base_target not found")

with open('public/css/style.css', 'w', encoding='utf-8') as f:
    f.write(text)

print("Updated public/css/style.css successfully!")
