<?php
/*
v21

Inspired by an awk BibTeX parser written by Nelson H. F. Beebe over 20 years ago although 
little of that remains.

Released through http://bibliophile.sourceforge.net under the GPL licence.
Do whatever you like with this -- some credit to the author(s) would be appreciated.

A collection of PHP classes to manipulate bibtex files.

If you make improvements, please consider contacting the administrators at bibliophile.sourceforge.net 
so that your improvements can be added to the release package.

Mark Grimshaw 2006
http://bibliophile.sourceforge.net

(Amendments to file reading Daniel Pozzi for v1.1)

11/June/2005 - v1.53 Mark Grimshaw:  Stopped expansion of @string when entry is enclosed in {...} or "..."
21/08/2004 v1.4 Guillaume Gardey, Added PHP string parsing and expand macro features.
 Fix bug with comments, strings macro.
    expandMacro = FALSE/TRUE to expand string macros.
    loadStringMacro($bibtex_string) to load a string. (array of lines)
22/08/2004 v1.4 Mark Grimshaw - a few adjustments to Guillaume's code.
28/04/2005 v1.5 Mark Grimshaw - a little debugging for @preamble

02/05/2005 G. Gardey - Add support for @string macro defined by curly brackets:
           @string{M12 = {December}}
                     - Don't expand macro for bibtexCitation and bibtexEntryType
                     - Better support for fields like journal = {Journal of } # JRNL23
03/05/2005 G. Gardey - Fix wrong field value parsing when an entry ends by
                           someField = {value}}

    v2 ****************************************** v2
                           
30/01/2006 v2.0 Esteban Zimanyi 
    - Add support for @string defined by other strings as in @string( AA = BB # " xx " # C }
    - Add support for comments as defined in Bibtex, i.e., ignores everything that is outside
      entries delimited by '@' and the closing delimiter. In particular, comments in Bibtex do not 
      necessarily have a % at the begining of the line !
This required a complete rewrite of many functions as well as writing new ones !

31/01/2006 Mark Grimshaw
   - Ensured that @comment{...} is ignored in parseEntry().
   - Modified extractEntries() to ensure that entries where the start brace/parenthesis is on a 
     new line are properly parsed.

*/

// For a quick command-line test (php -f PARSEENTRIES.php) after installation, uncomment these lines:

/*************************
// Parse a file
    $parse = NEW PARSEENTRIES();
    $parse->expandMacro = TRUE;
//  $array = array("RMP" =>"Rev., Mod. Phys.");
//  $parse->loadStringMacro($array);
//  $parse->removeDelimit = FALSE;
//  $parse->fieldExtract = FALSE;
    $parse->openBib("bib.bib");
    $parse->extractEntries();
    $parse->closeBib();
    list($preamble, $strings, $entries) = $parse->returnArrays();
    print_r($preamble);
    print "\n";
    print_r($strings);
    print "\n";
    print_r($entries);
    print "\n\n";
*************************/

/************************
// Parse a bibtex PHP string
    $bibtex_data = <<< END

@STRING{three = "THREE"}
@STRING{two = "TWO"}
@string{JRNL23 = {NatLA 23 } # " " # two # " " # three}

@article{klitzing.1,
    author = "v. Klitzing and Dorda and Pepper",
    title = "New method for high mark@sirfragalot.com accuracy determination of fine structure constant based on quantized hall resistance",
    volume = "45",
    journal = {Journal of } # JRNL23,
    pages = "494",
               citeulike-article-id = {12222
    }
               ,
               ignoreMe = {blah}, }    

@article
{
klitzing.2,
    author = "Klaus von Klitzing",
    title = "The Quantized Hall Effect",
    volume = "58",
    journal = two,
    pages = "519",
}

END;

    $parse = NEW PARSEENTRIES();
    $parse->expandMacro = TRUE;
//  $parse->removeDelimit = FALSE;
//  $parse->fieldExtract = FALSE;
    $array = array("RMP" =>"Rev., Mod. Phys.");
    $parse->loadStringMacro($array);
    $parse->loadBibtexString($bibtex_data);
    $parse->extractEntries();
    list($preamble, $strings, $entries) = $parse->returnArrays();
    print_r($preamble);
    print "\n";
    print_r($strings);
    print "\n";
    print_r($entries);
    print "\n\n";

**********************/

class PARSEENTRIES
{
    function __construct()
    {
        $this->preamble = $this->strings = $this->entries = array();
        $this->count = 0;
        $this->fieldExtract = TRUE;
        $this->removeDelimit = TRUE;
        $this->expandMacro = FALSE;
        $this->parseFile = TRUE;
        $this->outsideEntry = TRUE;
    }
// Open bib file
    function openBib($file)
    {
        if(!is_file($file))
            die;
        $this->fid = fopen ($file,'r');
        $this->parseFile = TRUE;
    }
// Load a bibtex string to parse it
    function loadBibtexString($bibtex_string)
    {
        if(is_string($bibtex_string))
            $this->bibtexString = explode("\n",$bibtex_string);    
        else
            $this->bibtexString = $bibtex_string;   
        $this->parseFile = FALSE;
        $this->currentLine = 0;
    }
// Set strings macro
    function loadStringMacro($macro_array)
    {
        $this->userStrings = $macro_array;
    }
// Close bib file
    function closeBib()
    {
        fclose($this->fid);
    }
// Get a non-empty line from the bib file or from the bibtexString
    function getLine()
    {
        if($this->parseFile)
        {
            if(!feof($this->fid))
            {
                do
                {
                    $line = trim(fgets($this->fid));
                }
                while(!feof($this->fid) && !$line);
                return $line;
            }
            return FALSE;
        }
        else
        {
            do
            {
                $line = trim($this->bibtexString[$this->currentLine]);
                $this->currentLine++;
            }
            while($this->currentLine < count($this->bibtexString) && !$line);
            return $line;
        }
    }
// Extract value part of @string field enclosed by double-quotes or braces.
// The string may be expanded with previously-defined strings
    function extractStringValue($string) 
    {
        // $string contains a end delimiter, remove it
        $string = trim(substr($string,0,strlen($string)-1));
        // remove delimiters and expand
        $string = $this->removeDelimitersAndExpand($string);
        return $string;
    }
// Extract a field
    function fieldSplit($seg)
    {
// echo "**** ";print_r($seg);echo "<BR>";
        // handle fields like another-field = {}
        $array = preg_split("/,\s*([-_.:,a-zA-Z0-9]+)\s*={1}\s*/U", $seg, PREG_SPLIT_DELIM_CAPTURE);
// echo "**** ";print_r($array);echo "<BR>";
        //$array = preg_split("/,\s*(\w+)\s*={1}\s*/U", $seg, PREG_SPLIT_DELIM_CAPTURE);
        if(!array_key_exists(1, $array))
            return array($array[0], FALSE);
        return array($array[0], $array[1]);
    }
// Extract and format fields
    function reduceFields($oldString)
    {
        // 03/05/2005 G. Gardey. Do not remove all occurences, juste one
        // * correctly parse an entry ended by: somefield = {aValue}}
        $lg = strlen($oldString);
        if($oldString[$lg-1] == "}" || $oldString[$lg-1] == ")" || $oldString[$lg-1] == ",")
            $oldString = substr($oldString,0,$lg-1);
        // $oldString = rtrim($oldString, "}),");
        $split = preg_split("/=/", $oldString, 2);
        $string = $split[1];
        while($string)
        {
            list($entry, $string) = $this->fieldSplit($string);
            $values[] = $entry;
        }
        foreach($values as $value)
        {
            $pos = strpos($oldString, $value);
            $oldString = substr_replace($oldString, '', $pos, strlen($value));
        }
        $rev = strrev(trim($oldString));
        if($rev[0] != ',')
            $oldString .= ',';
        $keys = preg_split("/=,/", $oldString);
        // 22/08/2004 - Mark Grimshaw
        // I have absolutely no idea why this array_pop is required but it is.  Seems to always be 
        // an empty key at the end after the split which causes problems if not removed.
        array_pop($keys);
        foreach($keys as $key)
        {
            $value = trim(array_shift($values));
            $rev = strrev($value);
            // remove any dangling ',' left on final field of entry
            if($rev[0] == ',')
                $value = rtrim($value, ",");
            if(!$value)
                continue;
            // 21/08/2004 G.Gardey -> expand macro
            // Don't remove delimiters now needs to know if the value is a string macro
            // $this->entries[$this->count][strtolower(trim($key))] = trim($this->removeDelimiters(trim($value)));
            $key = strtolower(trim($key));
            $value = trim($value);
            $this->entries[$this->count][$key] = $value;
        }
// echo "**** ";print_r($this->entries[$this->count]);echo "<BR>";
    }
// Start splitting a bibtex entry into component fields.
// Store the entry type and citation.
    function fullSplit($entry)
    {        
        $matches = preg_split("/@(.*)[{(](.*),/U", $entry, 2, PREG_SPLIT_DELIM_CAPTURE); 
        $this->entries[$this->count]['bibtexEntryType'] = strtolower(trim($matches[1]));
        // sometimes a bibtex entry will have no citation key
        if(preg_match("/=/", $matches[2])) // this is a field
            $matches = preg_split("/@(.*)\s*[{(](.*)/U", $entry, 2, PREG_SPLIT_DELIM_CAPTURE);
        // print_r($matches); print "<P>";
        $this->entries[$this->count]['bibtexCitation'] = $matches[2];
        $this->reduceFields($matches[3]);
    }

// Grab a complete bibtex entry
    function parseEntry($entry)
    {
        $count = 0;
        $lastLine = FALSE;
        if(preg_match("/@(.*)([{(])/U", preg_quote($entry), $matches)) 
        {
            if(!array_key_exists(1, $matches))
                return $lastLine;
            if(preg_match("/string/i", trim($matches[1])))
                $this->strings[] = $entry;
            else if(preg_match("/preamble/i", trim($matches[1])))
                $this->preamble[] = $entry;
            else if(preg_match("/comment/i", $matches[1])); // MG (31/Jan/2006) -- ignore @comment
            else
            {
                if($this->fieldExtract)
                    $this->fullSplit($entry);
                else
                    $this->entries[$this->count] = $entry;
                $this->count++;
            }
        return $lastLine;
        }
    }

// Remove delimiters from a string
    function removeDelimiters($string)
    {
        if($string  && ($string[0] == "\""))
        {
            $string = substr($string, 1);
            $string = substr($string, 0, -1);
        }
        else if($string && ($string[0] == "{"))
        {
            if(strlen($string) > 0 && $string[strlen($string)-1] == "}")
            {
                $string = substr($string, 1);
                $string = substr($string, 0, -1);
            }
        }
        return $string;
    }

// This function works like explode('#',$val) but has to take into account whether
// the character # is part of a string (i.e., is enclosed into "..." or {...} ) 
// or defines a string concatenation as in @string{ "x # x" # ss # {xx{x}x} }
    function explodeString($val)
    {
        $openquote = $bracelevel = $i = $j = 0; 
        while ($i < strlen($val))
        {
            if ($val[$i] == '"')
                $openquote = !$openquote;
            elseif ($val[$i] == '{')
                $bracelevel++;
            elseif ($val[$i] == '}')
                $bracelevel--;
            elseif ( $val[$i] == '#' && !$openquote && !$bracelevel )
            {
                $strings[] = substr($val,$j,$i-$j);
                $j=$i+1;
            }
            $i++;
        }
        $strings[] = substr($val,$j);
        return $strings;
    }

// This function receives a string and a closing delimiter '}' or ')' 
// and looks for the position of the closing delimiter taking into
// account the following Bibtex rules:
//  * Inside the braces, there can arbitrarily nested pairs of braces,
//    but braces must also be balanced inside quotes! 
//  * Inside quotes, to place the " character it is not sufficient 
//    to simply escape with \": Quotes must be placed inside braces. 
    function closingDelimiter($val,$delimitEnd)
    {
//  echo "####>$delimitEnd $val<BR>";
        $openquote = $bracelevel = $i = $j = 0; 
        while ($i < strlen($val))
        {
            // a '"' found at brace level 0 defines a value such as "ss{\"o}ss"
            if ($val[$i] == '"' && !$bracelevel)
                $openquote = !$openquote;
            elseif ($val[$i] == '{')
                $bracelevel++;
            elseif ($val[$i] == '}')
                $bracelevel--;
            if ( $val[$i] == $delimitEnd && !$openquote && !$bracelevel )
                return $i;
            $i++;
        }
// echo "--> $bracelevel, $openquote";
        return 0;
    }

// Remove enclosures around entry field values.  Additionally, expand macros if flag set.
    function removeDelimitersAndExpand($string, $inpreamble = FALSE)
    {
        // only expand the macro if flag set, if strings defined and not in preamble
        if(!$this->expandMacro || empty($this->strings) || $inpreamble)
            $string = $this->removeDelimiters($string);
        else
        {
            $stringlist = $this->explodeString($string);
            $string = "";
            foreach ($stringlist as $str)
            {
                // trim the string since usually # is enclosed by spaces
                $str = trim($str); 
                // replace the string if macro is already defined
                // strtolower is used since macros are case insensitive
                if (isset($this->strings[strtolower($str)]))
                    $string .= $this->strings[strtolower($str)];
                else 
                    $string .= $this->removeDelimiters(trim($str));
            }
        }
        return $string;
    }

// This function extract entries taking into account how comments are defined in BibTeX.
// BibTeX splits the file in two areas: inside an entry and outside an entry, the delimitation 
// being indicated by the presence of a @ sign. When this character is met, BibTex expects to 
// find an entry. Before that sign, and after an entry, everything is considered a comment! 
    function extractEntries()
    {
        $inside = $possibleEntryStart = FALSE;
        $entry="";
        while($line=$this->getLine())
        {
            if($possibleEntryStart)
                $line = $possibleEntryStart . $line;
            if (!$inside && strchr($line,"@"))
            {
                // throw all characters before the '@'
                $line=strstr($line,'@');
                if(!strchr($line, "{") && !strchr($line, "("))
                    $possibleEntryStart = $line;
                elseif(preg_match("/@.*([{(])/U", preg_quote($line), $matches))
                {
                    $inside = TRUE;
                    if ($matches[1] == '{')
                        $delimitEnd = '}';
                    else
                        $delimitEnd = ')';
                    $possibleEntryStart = FALSE;
                }
            }
            if ($inside)
            {
                $entry .= " ".$line;
                if ($j=$this->closingDelimiter($entry,$delimitEnd))
                {
                    // all characters after the delimiter are thrown but the remaining 
                    // characters must be kept since they may start the next entry !!!
                    $lastLine = substr($entry,$j+1);
                    $entry = substr($entry,0,$j+1);
                    $this->parseEntry($entry);
                    $entry = strchr($lastLine,"@");
                    if ($entry) 
                        $inside = TRUE;
                    else 
                        $inside = FALSE;
                }
            }
        }
    }

// Return arrays of entries etc. to the calling process.
    function returnArrays()
    {
        foreach($this->preamble as $value)
        {
            preg_match("/.*[{(](.*)/U", $value, $matches);
            $preamble = substr($matches[1], 0, -1);
            $preambles['bibtexPreamble'] = trim($this->removeDelimitersAndExpand(trim($preamble), TRUE));
        }
        if(isset($preambles))
            $this->preamble = $preambles;
        if($this->fieldExtract)
        {
            // Next lines must take into account strings defined by previously-defined strings
            $strings = $this->strings; 
            // $this->strings is initialized with strings provided by user if they exists
            // it is supposed that there are no substitutions to be made in the user strings, i.e., no # 
            $this->strings = isset($this->userStrings) ? $this->userStrings : array() ; 
            foreach($strings as $value) 
            {
                // changed 21/08/2004 G. Gardey
                // 23/08/2004 Mark G. account for comments on same line as @string - count delimiters in string value
                $value = trim($value);
                $matches = preg_split("/@\s*string\s*([{(])/i", $value, 2, PREG_SPLIT_DELIM_CAPTURE);
                $delimit = $matches[1];
                $matches = preg_split("/=/", $matches[2], 2, PREG_SPLIT_DELIM_CAPTURE);
                // macros are case insensitive
                $this->strings[strtolower(trim($matches[0]))] = $this->extractStringValue($matches[1]); 
            }
        }
        // changed 21/08/2004 G. Gardey
        // 22/08/2004 Mark Grimshaw - stopped useless looping.
        // removeDelimit and expandMacro have NO effect if !$this->fieldExtract
        if($this->removeDelimit || $this->expandMacro && $this->fieldExtract)
        {
            for($i = 0; $i < count($this->entries); $i++)
            {
                foreach($this->entries[$i] as $key => $value)
                // 02/05/2005 G. Gardey don't expand macro for bibtexCitation 
                // and bibtexEntryType
                if($key != 'bibtexCitation' && $key != 'bibtexEntryType')
                    $this->entries[$i][$key] = trim($this->removeDelimitersAndExpand($this->entries[$i][$key])); 
            }
        }
// EZ 
//      if(empty($this->preamble))
//          $this->preamble = FALSE;
//      if(empty($this->strings))
//          $this->strings = FALSE;
//      if(empty($this->entries))
//          $this->entries = FALSE;
        return array($this->preamble, $this->strings, $this->entries);
    }
}
?>

