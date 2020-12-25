<?php

/**
 * Create the table of content for GitLab wikis.
 *
 * This class will get the list of .docx/.md/.pdf files in
 * subfolders and generate a_sidebar.md file.
 *
 * For each markdown file, extract his heading 1 if there is one
 * to use it in the navigation content
 *
 * php version 7.2
 *
 * @package   Generate_Wiki_Sidebar
 * @author    Christophe Avonture <christophe@avonture.be>
 * @license   MIT
 */

namespace Avonture\Classes;

/**
 * Author      : Christophe Avonture
 * Written date: september 2019
 */
class GenerateWikiSidebar
{
    const DS = DIRECTORY_SEPARATOR;

    // Name of the sidebar file used by gitlab
    const SIDEBAR = '_sidebar.md';

    /**
     * Comment that will be added at the top and at the bottom
     * of the generated sidebar.
     */
    const WARNING = '<!-- ' . PHP_EOL .
        '   Sidebar generated automatically by https://github.com/cavo789/generate_wiki_sidebar,' . PHP_EOL .
        '   don\'t update manually ' . PHP_EOL .
        '   Last updated: @LASTUPDATE@ ' . PHP_EOL . '-->';

    /**
     * List of file's extensions that will be mentionned in the sidebar
     * For instance, sidebar will provide a link to a docx, html, md, ... file.
     */
    const EXTENSIONS = ['DOCX', 'HTML', 'MD', 'PDF', 'PPTX', 'XLSX'];

    /**
     * The current folder; where the Wiki is stored.
     *
     * @var string
     */
    private $wikiRoot = '';

    /**
     * Contains all paths (folder/subfolder) of the current wiki.
     *
     * @var array<mixed>
     */
    private $treeStructure = [];

    /**
     * Command line options.
     *
     * @var array<mixed>
     */
    private $options = [];

    /**
     * Settings is a generate_wiki_sidebar.json file was found.
     *
     * @var array<mixed>
     */
    private $settings = [];

    /**
     * Class constructor.
     *
     * @param array|null $options Command line options ($argv))
     */
    public function __construct(?array $options)
    {
        $this->options = $options;

        date_default_timezone_set('Europe/Brussels');

        // Get the list of files under the root folder; loop all sub-folders
        // Use getcwd() and not __DIR__ which can be wrong in case of
        // symlinked script;
        $this->wikiRoot = (string) getcwd();

        $this->loadSettings();

        $this->processCommandLineOptions();
    }

    /**
     * Create the sidebar file.
     *
     * @return string Return the name of the file that was created
     */
    public function createSideBar(): string
    {
        $this->treeStructure = $this->getTreeStructure();

        $sContent = $this->makeSidebarContent($this->treeStructure);

        // Rewrite the _sidebar.md file
        $filename = $this->wikiRoot . '/' . self::SIDEBAR;
        file_put_contents($filename, $sContent);

        return $filename;
    }

    /**
     * Load the generate_wiki_sidebar.json if there is one.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @return void
     */
    private function loadSettings()
    {
        $json     = '';
        $filename = 'generate_wiki_sidebar.json';
        $path     = $this->wikiRoot . self::DS;

        // Try to locate the json file in the root folder of the wiki
        if (is_file($path . $filename)) {
            $json = $path . $filename;
        }

        // If not found, try in a .config sub-folder
        $path .= '.config' . self::DS;
        if ('' === $json) {
            if (is_file($path . $filename)) {
                $json = $path . $filename;
            }
        }

        if ('' !== $json) {
            $this->settings = (array) json_decode((string)file_get_contents($json), true);
            if (JSON_ERROR_NONE !== \json_last_error()) {
                printf(
                    "Generate_Wiki_Sidebar - Error in file %s\nFile content:\n\n%s\n",
                    $json,
                    file_get_contents($json)
                );
                throw new \Exception('json_decode error: ' . \json_last_error_msg());
            }
        }

        // Add extra settings if not in the JSON file
        if (!isset($this->settings['keepFileName'])) {
            $this->settings['keepFileName'] = 0;
        }

        /* Process the whitelist
         * Make files / folders absolute
         * Replace "chapter1/file.md" f.i. by "c:\repo\wiki\chapter1\file.md"
         */
        if (isset($this->settings['whitelist'])) {
            foreach ($this->settings['whitelist'] as $key => $value) {
                $name = $this->wikiRoot . self::DS . rtrim(ltrim($value, '/'), '/');
                $name = str_replace('/', self::DS, $name);

                $this->settings['whitelist'][$key] = $name;
            }
        } else {
            $this->settings['whitelist'] = [];
        }

        /* Exclude these folders from the dir scan
         * Make files / folders absolute
         * Replace "chapter1" f.i. by "c:\repo\wiki\chapter1\"
         */
        if (isset($this->settings['exclude'])) {
            foreach ($this->settings['exclude'] as $key => $value) {
                $name = $this->wikiRoot . self::DS . rtrim(ltrim($value, '/'), '/');
                $name = str_replace('/', self::DS, $name) . self::DS;

                $this->settings['exclude'][$key] = $name;
            }
        }
    }

    /**
     * Read command line options and initialize properties.
     *
     * @return bool If something goes wrong, return false
     */
    private function processCommandLineOptions(): bool
    {
        // Process command line options
        if (null === $this->options) {
            return false;
        }

        if (count($this->options) > 0) {
            for ($argumentCount = 1; $argumentCount < count($this->options); $argumentCount++) {
                if ('--keep-file-name' === $this->options[$argumentCount]) {
                    $this->settings['keepFileName'] = 1;
                } else {
                    echo 'Invalid argument: ' . $this->options[$argumentCount] . PHP_EOL;

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * From a markdown content, return an heading text
     * (by default the "# TEXT" i.e. the heading 1).
     *
     * @param string $markdown Markdown content
     * @param string $heading  Level of heading to retrieve
     *
     * @suppress PhanUnusedVariableCaughtException
     *
     * @return string
     */
    private function getHeadingText(string $markdown, string $heading = '#'): string
    {
        // Try to find a heading 1 and if so use that text for the
        // title tag of the generated page
        $matches = [];
        $title   = '';

        try {
            preg_match('/' . $heading . ' ?(.*)/', $markdown, $matches);
            $title = (count($matches) > 0) ? trim($matches[1]) : '';

            // With markdown, it's possible to add extra configuration
            // like in "# MyTitle {#title}" i.e. specifying here the
            // name for the anchor. Remove it, keep only the title
            $title = preg_replace("/\{[^}]*}/", '', $title);

            // Trim, remove empty chars, carriage return, ... and # too
            $title = trim($title, " \t\n\r\0\x0B#");
        } catch (\Exception $e) {
        }

        return $title;
    }

    /**
     * Confirm or not if a string is starts with ...
     *     startsWith('Laravel', 'Lara') ==> true.
     *
     * @param string $string The string
     * @param string $prefix The prefix to search
     *
     * @see https://stackoverflow.com/a/834355/1065340
     *
     * @return bool True when the string is ending with that prefix
     */
    private function startsWith(string $string, string $prefix): bool
    {
        $length = strlen($prefix);

        return boolval(substr(strtolower($string), 0, $length) === strtolower($prefix));
    }

    /**
     * Return the list of files (having search extensions) under the directory
     * root folder i.e. in any sub-directories.
     *
     * This function will create a tree structure i.e. an associative array
     * with so many "deep" that we've sub-folders
     *
     * @param string $folder The folder that is currently processed
     *                       (since this function is recursive)
     *
     * @return array Return the list of files, names will be relative to the $rootFolder
     */
    private function getTreeStructure(string $folder = ''): array
    {
        // At the very first call, $folder can be empty and if so, will
        // be initialized to the wiki root folder
        if ('' == $folder) {
            $folder = $this->wikiRoot;
        }

        $folder = new \DirectoryIterator($folder);

        $dirs  = [];
        $files = [];

        foreach ($folder as $node) {
            if ($node->isDir() && !$node->isDot()) {
                // We've a folder

                // Process it only if not mentionned in the list of exclusions
                if (!in_array($node->getFilename(), ['.git'])) {
                    // Recursive call, goes deeper in the structure
                    $tree = $this->getTreeStructure($node->getPathname());

                    if (count($tree) > 0) {
                        // Only mention the folder in the Tree if we've
                        // .md files in it
                        $dirs[$node->getFilename()] = $tree;
                    }
                }
            } elseif ($node->isFile()) {
                // We've a file, add in the sidebar if the file should be taken
                if (!($this->shouldBeIgnored($node))) {
                    // Process only .md files; case insensitive
                    $name    = $node->getFilename();
                    $files[] = $name;
                }
            }
        }

        // Sort the array on his key; case insensitive
        uksort($dirs, 'strcasecmp');
        uksort($files, 'strcasecmp');

        // Merge arrays and return the list
        return $dirs + $files;
    }

    /**
     * This function will check if a file should be ignored i.e. not mentioned
     * in the sidebar.
     *
     * The file will be ignored when:
     *     - stored in the wiki root folder
     *     - stored in a folder that is specified in the list of folders to exclude
     *     - when not a supported extensions
     *
     * The file will not be ignored when:
     *     - mentioned in the whitelist array (whatever its extension)
     *     - stored in a folder not mentione din the list of folders to exclude
     *
     * @param \DirectoryIterator $file A file
     *
     * @return bool Return TRUE when the file should be ignored
     */
    private function shouldBeIgnored(\DirectoryIterator $file): bool
    {
        /*
         * If the file is mentioned in the whitelist, the file SHOULD be
         * taken in the sidebar no matter the exclusions setting
         */
        if (in_array($file->getPathname(), $this->settings['whitelist'])) {
            return false;
        }

        // Don't process .md files immediately under the root
        if (dirname($file->getPathname()) === $this->wikiRoot) {
            return true;
        }

        /*
         * Get the file's extension. If not mentionned in the list of
         * supported extensions; ignore the file
         */
        $ext = strtoupper($file->getExtension());
        if (false === (in_array($ext, self::EXTENSIONS))) {
            return true;
        }

        if (isset($this->settings['exclude'])) {
            $j = count($this->settings['exclude']);

            $filename = $file->getPathname();

            for ($i=0; $i < $j; $i++) {
                if ($this->startsWith($filename, $this->settings['exclude'][$i])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Generate the HTML content for the sidebar.
     *
     * @param array  $treeStructure List of files, obtained thanks the getListOfFiles function
     * @param string $folderName    Name of the folder
     * @param string $indent        Indentation
     * @param bool   $keepFileName  Keep filename or use heading?
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @return string The HTML content for the sidebar
     */
    private function makeSidebarContent(
        array $treeStructure,
        string $folderName = '',
        string $indent = '',
        bool $keepFileName = false
    ): string {
        $sidebar='';

        $arrFiles = [];

        foreach ($treeStructure as $key => $value) {
            if (is_array($value)) {
                // If we've an array, we've thus a sub-folder; create a new level (accordion)
                // and make a recursive call
                $sidebar .=
                // $key is the base name of the folder (like "fr" and not the "/doc/userguide/fr" f.i.)
                $indent . '<details><!--' . $key . '-->' . PHP_EOL .
                $indent . '   <summary>' . ucfirst($key) . '</summary>' . PHP_EOL .
                $indent . '   <blockquote>' . PHP_EOL .
                $indent . '      <ul>' . PHP_EOL;

                $sidebar .= $this->makeSidebarContent(
                    $value,
                    $folderName . self::DS . $key,
                    $indent . '         ',
                    $keepFileName
                );

                $sidebar .=
                $indent . '      </ul>' . PHP_EOL .
                $indent . '   </blockquote>' . PHP_EOL .
                $indent . '</details>' . PHP_EOL;
            } else {
                // $value is a file; get his path
                $filename = '.' . $folderName . self::DS . $value;

                $ext = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));

                // By default, the title that will be used in the sidebar
                // will be the relative name of the markdown file
                $title = basename($filename);

                // Don't keep the ".md" suffix in the title
                if ('.md' === substr($title, -3)) {
                    $title = substr($title, 0, strlen($title) - 3);
                }

                // But if the following setting is false, we'll extract the
                // H1 present in the note
                if (0 === $this->settings['keepFileName']) {
                    $h1 = '';

                    // Read the file and try to get the H1
                    if ('MD' === $ext) {
                        $markdown = (string) file_get_contents($filename, true);

                        $h1 = trim($this->getHeadingText($markdown));
                    }

                    if ('' !== $h1) {
                        $title = $h1;
                    }
                }

                // We need to use Unix slashe;
                $filename = str_replace(self::DS, '/', $filename);

                // Remove the ".md" suffix; required by wiki to make the link
                // to the HTML version of the file and not the .MD one
                if ('.md' === substr($filename, -3)) {
                    $filename = substr($filename, 0, strlen($filename) - 3);
                }

                $arrFiles[$title] = $filename;
            }
        }

        // Sort the array based on his key and thus, on the H1 title
        ksort($arrFiles);

        // We can now build our sidebar content since our files are
        // alphabetically sorted on their H1

        foreach ($arrFiles as $key => $value) {
            $sidebar .= $indent . '<li>[' . $key . '](' . $value . ')' . '</li>' . PHP_EOL;
        }

        if ('' === $indent) {
            // Add a small warning but only when we've process the full tree
            // We can detect this when $indent is empty

            // Get the now datetime and put it in the warning comment
            $warning = str_replace('@LASTUPDATE@', date('Y-m-d H:i:s'), self::WARNING);

            // Add a warning as HTML comment
            $sidebar = $warning . PHP_EOL .
                '<!-- markdownlint-disable MD033 -->' . PHP_EOL . PHP_EOL .
                ltrim($sidebar, PHP_EOL) .
                $warning . PHP_EOL;
        }

        return $sidebar;
    }
}
