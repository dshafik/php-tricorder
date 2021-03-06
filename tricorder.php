<?php
/**
 * PHP-Tricorder
 *
 * A CLI utility that will scan a structure file created using
 * phpDocumentor and give you some suggestions on how to test
 * the classes and methods present in the structure file
 *
 * @author Chris Hartjes
 * @version 0.1
 */
array_shift($argv);

if (count($argv) == 0 || $argv[0] == '--help') {
    echo "PHP-Tricoder - by Chris Hartjes" . PHP_EOL . PHP_EOL;
    echo "PHP-Tricoder analyzes phpDocumentor output to provide" . PHP_EOL;
    echo "suggestions on test scenarios and point out potential" . PHP_EOL;
    echo "problems" . PHP_EOL . PHP_EOL;
    echo "Usage: php tricoder.php </path/to/structure.xml>" . PHP_EOL . PHP_EOL;;
    exit();
}

// Let's see if we have an actual file
$structureFile = $argv[0];

if (!file_exists($structureFile)) {
    echo "Could not find phpDocumenter file [{$structureFile}]" . PHP_EOL . PHP_EOL;
}

// Load in our structure and start iterating through it
echo "Reading in phpDocumentor structure file..." . PHP_EOL . PHP_EOL;

// I hate suppressing error messages, but we are trapping the results later
$structureData = @simplexml_load_file($structureFile);

if (!$structureData) {
    echo "{$structureFile} is not a properly formatted phpDocumentor structure";
    echo " file, please verify it's contents" . PHP_EOL;
    exit();
}
        
$files = $structureData->{'file'};

if (!$files) {
    echo "Could not find proper file information in {$structureFile}" . PHP_EOL;
}

foreach ($files as $file) {
    echo $file['path'] . PHP_EOL . PHP_EOL;
    scanClasses($file->class);
    dependencyCheck($file['path']);
    echo "\n";
}

/**
 * Read in our file, analyze the tokens and look for classes that might be
 * dependencies that need to be injected
 */
function dependencyCheck($pathToFile) {
    $tokens = token_get_all(file_get_contents($pathToFile));
    $dependencyFlag = false;
    $depCount = 1;
    $dependencyName = '';

    foreach ($tokens as $idx => $token) {
        if ($dependencyFlag === true) {
            // If we encounter a opening (, then we know we have found
            // our dependency
            if (!is_string($token)) {
                $dependencyName .= $token[1];
            } else {
                $dependencyFlag = false;
                $dependencyName = trim($dependencyName);
                echo "{$dependencyName} might need to be injected for testing purposes\n";
            }
        }

        if (is_long($token[0]) && token_name($token[0]) == 'T_NEW') {
            $dependencyFlag = true;
            $dependencyName = '';
        }
    }

    // Grab the tokens
    // Iterate through each token
    // if we previously found T_NEW, grab name of class
    // display name of class as potential dependency to inject
    // else when we discover a T_NEW, flag it
}

/**
 * Scan our classes to look for methods
 *
 * @param string $classXml
 */
function scanClasses($classXml) {
    foreach ($classXml as $classInfo) {
        echo "Scanning " . $classInfo->{'name'} . PHP_EOL . PHP_EOL;
        scanMethods($classInfo->method);
    }
}

/**
 * Scan through our methods and find out if we have any parameters that we
 * need to check for type
 *
 * @param SimpleXMLElement $methods
 */
function scanMethods($methods) {
    foreach ($methods as $method) {
        $methodHasSuggestions = isVisibile(
            (string)$method->name,
            (string)$method['visibility']
        );

        // Convert our tag information into an array for easy manipulation
        $methodTags = json_decode(json_encode((array)$method->docblock->tag), 1);

        // Check to see if we have any parameters that we need to test
        $paramTags = array_filter($methodTags, function($tag) {
            if (isset($tag['name']) && $tag['name'] == 'param') {
                return true;
            }
        });

        $argsHaveSuggestions = scanArguments(
            (string)$method->name, 
            $paramTags
        );

        // Grab our method return information 
        $returnTag = array_filter($methodTags, function($tag) {
            if (isset($tag['name']) &&$tag['name'] == 'return') {
                return true;
            }
        });
        
        $returnTypeHasSuggestions = processReturnType(
            (string)$method->name,
            $returnTag
        );

        echo ($methodHasSuggestions == false 
            && $argsHaveSuggestions == false
            && $returnTypeHasSuggestions == false)
            ? '' 
            : PHP_EOL;
    }
}

/**
 * Determine if the method passed in is publically visible
 *
 * @param string $methodName
 * @param string $visibility
 */
function isVisibile($methodName, $visibility) {
    $methodIsVisible = false;

    // If a method is protected, flag it as hard-to-test
    if ($visibility !== 'public') {
        $methodIsVisible = true;
        echo "{$methodName} -- non-public methods are difficult to test in isolation" . PHP_EOL;
    }

    return $methodIsVisible;
}

/**
 * Iterate through our list of arguments for the method, examining the tags
 * to see what the types are
 *
 * @param string $methodName
 * @param array $tags
 * @return boolean
 */
function scanArguments($methodName, $tags) {
    $argumentsHaveSuggestions = array();

    foreach ($tags as $tag) {
        $argumentsHaveSuggestions[] = processArgumentType($methodName, $tag);
    }

    return in_array(true, $argumentsHaveSuggestions);
}

/**
 * Look at the argument type and react accordingly
 *
 * @param string $methodName
 * @param array $tag
 * @return boolean
 */
function processArgumentType($methodName, $tag) {
    $acceptedTypes = array(
        'array',
        'string', 
        'integer'
    );
    $argHasSuggestions = false;
    $varName = $tag['variable'];
    $tagType = $tag['type'];

    /**
     * Sometimes people send us param types like bool|string, so we need to
     * search for those and convert them to 'mixed'
     */
    if (stristr('|', $tagType) === true) {
        $tagType = 'mixed';
    }
    
    switch ($tagType) {
        case 'array':
            $msg = "test {$varName} using an empty array()";
            $argHasSuggestions = true;
            break;
        case 'bool':
        case 'boolean':
            $msg = "test {$varName} using both true and false";
            $argHasSuggestions = true;
            break;
        case 'int':
        case 'integer':
            $msg = "test {$varName} using non-integer values";
            $argHasSuggestions = true;
            break;
        case 'mixed': 
            $msg = "test {$varName} using all potential values";
            $argHasSuggestions = true;
            break;
        case 'string':
            $msg = "test {$varName} using null or empty strings"; 
            $argHasSuggestions = true;
            break;
        case 'object':
        default:
            $msg = "mock {$varName} as {$tagType}";
            $argHasSuggestions = true;
            break;
    } 

    echo "{$methodName} -- {$msg}" . PHP_EOL;

    return $argHasSuggestions;
}

/**
 * Look at the return type and react accordingly
 *
 * @param string $methodName
 * @param array $tag
 * @return boolean
 */
function processReturnType($methodName, $tag) {
    // Flatten the array a bit so we can check for attributes
    $tagInfo = array_shift($tag);
    
    if ($tagInfo == NULL) {
        return false;
    }

    $tagType = $tagInfo['type'];
    
    /**
     * Sometimes people send us return types like bool|string, so we need to
     * search for those and convert them to 'mixed'
     */
    if (stristr('|', $tagType) === true) {
        $tagType = 'mixed';
    }
    
    switch ($tagType) {
        case 'mixed':
            $msg = "test method returns all potential values";
            break;
        case 'bool':
        case 'boolean':
            $msg = "test method returns boolean values";
            break;
        case 'int':
        case 'integer':
            $msg = "test method returns non-integer values";
            break;
        case 'string':
            $msg = "test method returns expected string values"; 
            break;
        default:
            $msg = "test method returns {$tagType} instances";
            break;
    } 

    echo "{$methodName} -- {$msg}" . PHP_EOL;
}

