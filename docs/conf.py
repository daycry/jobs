# Configuration file for the Sphinx documentation builder.
# Docs: https://www.sphinx-doc.org/en/master/usage/configuration.html

# -- Project information -----------------------------------------------------
project = 'CodeIgniter Jobs'
copyright = '2025, daycry'
author = 'daycry'

# -- General configuration ---------------------------------------------------
extensions = [
    'myst_parser',        # render the Markdown content pages
    'sphinx_copybutton',  # copy button on every code block
]

# MyST (Markdown) options.
myst_enable_extensions = [
    'linkify',      # auto-link bare URLs
    'colon_fence',  # ::: fenced admonitions
    'deflist',      # definition lists
]
myst_heading_anchors = 3  # generate #anchors for h1-h3 so cross-page links resolve

source_suffix = {
    '.rst': 'restructuredtext',
    '.md': 'markdown',
}

master_doc = 'index'

# Pages/dirs Sphinx must NOT try to build (internal specs/plans, MkDocs landing, stray READMEs).
exclude_patterns = [
    '_build',
    'Thumbs.db',
    '.DS_Store',
    'index.md',        # MkDocs landing; Sphinx uses index.rst
    'home.md',         # legacy page
    'superpowers',     # internal specs/plans — never published
    'superpowers/**',
    'README.md',
    '**/README.md',
]

# -- Options for HTML output (Furo) ------------------------------------------
html_theme = 'furo'
html_title = 'CodeIgniter Jobs'
html_logo = 'images/logo.svg'
html_static_path = ['_static']
html_css_files = ['custom.css']

# Syntax highlighting: a clean light theme + a vibrant dark theme. Furo swaps
# between them automatically with the light/dark toggle.
pygments_style = 'friendly'
pygments_dark_style = 'monokai'

# Brand colours (CodeIgniter orange) wired into Furo's CSS variables.
html_theme_options = {
    'navigation_with_keys': True,
    'light_css_variables': {
        'color-brand-primary': '#d9411e',
        'color-brand-content': '#d9411e',
    },
    'dark_css_variables': {
        'color-brand-primary': '#ff7a59',
        'color-brand-content': '#ff7a59',
    },
}

# Strip shell/REPL prompts so copied snippets are runnable.
copybutton_prompt_text = r'>>> |\.\.\. |\$ '
copybutton_prompt_is_regexp = True
copybutton_only_copy_prompt_lines = False
