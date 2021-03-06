<?php
/**
 * Parses a string or stream of XML, calling back to a function when a
 * specified element is found
 *
 * @author David North
 * @package xmlStreamReader
 * @license http://opensource.org/licenses/mit-license.php
 */
class xmlStreamReader
{
    /**
     * @var array An array of registered callbacks
     */
    private $_callbacks = array();

    /**
     * @var string The current node path being investigated
     */
    private $_currentPath = '/';

    /**
     * @var array An array path data for paths that require callbacks
     */
    private $_pathData = array();

    /**
     * @var boolean Whether or not the object is currently parsing
     */
    private $_parse = FALSE;

    /**
     * Parses the XML provided using streaming and callbacks
     *
     * @param mixed $data Either a stream resource or string containing XML
     * @param int $chunkSize The size of data to read in at a time. Only
     * relevant if $data is a stream
     *
     * @return xmlStreamReader
     * @throws Exception
     */
    public function parse( $data, $chunkSize = 1024 )
    {
        //Ensure that the $data var is of the right type
        if ( !is_string( $data )
            && ( !is_resource( $data ) || get_resource_type($data) !== 'stream' )
        )
        {
            throw new Exception( 'Data must be a string or a stream resource' );
        }

        //Ensure $chunkSize is the right type
        if ( !is_int( $chunkSize ) )
        {
            throw new Exception( 'Chunk size must be an integer' );
        }

        //Initialise the object
        $this->_init();

        //Create the parser and set the parsing flag
        $this->_parse = TRUE;
        $parser       = xml_parser_create();

        //Set the parser up, ready to stream through the XML
        xml_set_object( $parser, $this );

        //Set up the protected methods _start and _end to deal with the start
        //and end tags respectively
        xml_set_element_handler( $parser, '_start', '_end' );

        //Set up the _addCdata method to parse any CDATA tags
        xml_set_character_data_handler( $parser, '_addCdata' );

        //For general purpose data, use the _addData method
        xml_set_default_handler( $parser, '_addData' );

        //If the data is a resource then loop through it, otherwise just parse
        //the string
        if ( is_resource( $data ) )
        {
            fseek( $data, 0 );
            while( $this->_parse && $chunk = fread($data, $chunkSize) )
            {
                $this->_parseString( $parser, $chunk, feof($data) );
            }
        }
        else
        {
            $this->_parseString( $parser, $data, TRUE );
        }

        //Free up the parser
        xml_parser_free( $parser );
        return $this;
    }

    /**
     * Registers a callback for a specified XML path
     *
     * @param string $path The path that the callback is for
     * @param function $callback The callback mechanism to use
     *
     * @return xmlStreamReader
     * @throws Exception
     */
    public function registerCallback( $path, $callback )
    {
        //Ensure the path is a string
        if ( !is_string( $path ) )
        {
            throw new Exception('Namespace must be a string');
        }

        //Ensure that the callback is callable
        if ( !is_callable( $callback ) )
        {
            throw new Exception('Callback must be callable');
        }

        //All tags and paths are lower cased, for consistency
        $path = strtolower($path);
        if ( substr($path, -1, 1) !== '/' )
        {
            $path .= '/';
        }

        //If this is the first callback for this path, initialise the variable
        if ( !isset( $this->_callbacks[$path] ) )
        {
            $this->_callback[$path] = array();    
        }

        //Add the callback
        $this->_callbacks[$path][] = $callback;
        return $this;
    }

    /**
     * Stops the parser from parsing any more. Because of the nature of
     * streaming there may be more data to read. If this is the case then no
     * further callbacks will be called.
     *
     * @return xmlStreamReader
     */
    public function stopParsing()
    {
        $this->_parse = FALSE;
        return $this;
    }

    /**
     * Initialise the object variables
     *
     * @return NULL
     */
    private function _init()
    {
        $this->_currentPath = '/';
        $this->_pathData    = array();
        $this->_parse       = FALSE;
    }

    /**
     * Parse data using xml_parse
     *
     * @param resource $parser The XML parser
     * @param string $data The data to parse
     * @param boolean $isFinal Whether or not this is the final part to parse
     *
     * @return NULL
     * @throws Exception
     */
    protected function _parseString( $parser, $data, $isFinal )
    {
        if (!xml_parse($parser, $data, $isFinal))
        {
            throw new Exception(
                xml_error_string( xml_get_error_code( $parser ) )
                .' At line: '.
                xml_get_current_line_number( $parser )
            );
        }
    }

    /**
     * Parses the start tag
     *
     * @param resource $parser The XML parser
     * @param string $tag The tag that's being started
     * @param array $attributes The attributes on this tag
     *
     * @return NULL
     */
    protected function _start( $parser, $tag, $attributes )
    {
        //Set the tag as lower case, for consistency
        $tag = strtolower($tag);

        //Update the current path
        $this->_currentPath .= $tag.'/';

        //Go through each callback and ensure that path data has been
        //started for it
        foreach( $this->_callbacks as $namespace => $callbacks )
        {
            if ( $namespace === $this->_currentPath )
            {
                $this->_pathData[ $this->_currentPath ] = '';
            }
        }

        //Generate the tag, with attributes. Attribute names are also lower
        //cased, for consistency
        $data = '<'.$tag;
        foreach ( $attributes as $key => $val )
        {
            $data .= ' '.strtolower($key).'="'.$val.'"';
        }
        $data .= '>';

        //Add the data to the path data required
        $this->_addData( $parser, $data );
    }

    /**
     * Adds CDATA to any paths that require it
     *
     * @param resource $parser
     * @param string $data
     *
     * @return NULL
     */
    protected function _addCdata( $parser, $data )
    {
        $this->_addData( $parser, '<![CDATA['.$data.']]>');
    }

    /**
     * Adds data to any paths that require it
     *
     * @param resource $parser
     * @param string $data
     *
     * @return NULL
     */
    protected function _addData( $parser, $data )
    {
        //Having a path data entry means at least 1 callback is interested in
        //the data. Loop through each path here and, if inside that path, add
        //the data
        foreach ($this->_pathData as $key => $val)
        {
            if ( strpos($this->_currentPath, $key) !== FALSE )
            {
                $this->_pathData[$key] .= $data;
            }
        }
    }

    /**
     * Parses the end of a tag
     *
     * @param resource $parser
     * @param string $tag
     *
     * @return NULL
     */
    protected function _end( $parser, $tag )
    {
        //Make the tag lower case, for consistency
        $tag = strtolower($tag);

        //Add the data to the paths that require it
        $data = '</'.$tag.'>';
        $this->_addData( $parser, $data );

        //Loop through each callback and see if the path matches the
        //current path
        foreach( $this->_callbacks as $path => $callbacks )
        {
            //If parsing should continue, and the paths match, then a callback
            //needs to be made
            if ( $this->_parse && $this->_currentPath === $path )
            {
                //Build the SimpleXMLElement object. As this is a partial XML
                //document suppress any warnings or errors that might arise
                //from invalid namespaces
                $data = new SimpleXMLElement(
                    $this->_pathData[ $path ],
                    LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING
                );

                //Loop through each callback. If one of them stops the parsing
                //then cease operation immediately
                foreach ( $callbacks as $callback )
                {
                    call_user_func_array( $callback, array($this, $data) );

                    if ( !$this->_parse )
                    {
                        break 2;
                    }
                }
            }
        }

        //Unset the path data for this path, as it's no longer needed
        unset( $this->_pathData[ $this->_currentPath ] );

        //Update the path with the new path (effectively moving up a directory)
        $this->_currentPath = substr(
            $this->_currentPath,
            0,
            strlen($this->_currentPath) - (strlen($tag) + 1)
        );
    }
}
