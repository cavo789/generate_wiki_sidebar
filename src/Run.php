<?php

/**
 * Run the tool and create the _sidebar.md file in the repo's root directory
 *
 * Create the table of content for GitLab wikis
 *
 * php version 7.2
 *
 * @package   Generate_Wiki_Sidebar
 * @author    Christophe Avonture <christophe@avonture.be>
 * @license   MIT
 */
namespace Avonture;

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Avonture\Classes\GenerateWikiSidebar;

const GREEN="\033[32m";
const CYAN="\033[36m";
const RESET="\033[0m";

// $argv is an array with command line options

$sidebar  = new GenerateWikiSidebar($argv);
$filename = $sidebar->createSideBar();
unset($sidebar);

echo GREEN."Generating the _sidebar.md file for wikis\n".RESET;
echo GREEN."-----------------------------------------\n\n".RESET;

printf(
    CYAN."File %s. has been created / updated\n".RESET,
    str_replace('/', DIRECTORY_SEPARATOR, $filename)
);
