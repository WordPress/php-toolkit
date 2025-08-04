<?php
/**
 * Benchmark script to compare WXR parser performance
 * 
 * Compares the parsing speed of:
 * - WXR_Parser_XMLProcessor_Simple (single pass using XMLProcessor)
 * - WXR_Parser_Entity_Reader (using WXREntityReader)
 * - WXR_Parser_XML (using PHP's XML extension)
 * - WXR_Parser_Regex (using regular expressions)
 */

// Include required files
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../components/DataLiberation/EntityReader/EntityReader.php';
require_once __DIR__ . '/../../components/DataLiberation/EntityReader/WXREntityReader.php';
require_once __DIR__ . '/../../components/DataLiberation/ImportEntity.php';
require_once __DIR__ . '/../../components/XML/XMLProcessor.php';
require_once __DIR__ . '/class-wxr-parser-entity-reader.php';
require_once __DIR__ . '/class-wxr-parser-xml.php';
require_once __DIR__ . '/class-wxr-parser-regex.php';

// Mock WP_Error for testing
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $errors = array();
        public $error_data = array();
        
        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( empty( $code ) ) {
                return;
            }
            $this->errors[ $code ][] = $message;
            if ( ! empty( $data ) ) {
                $this->error_data[ $code ] = $data;
            }
        }
    }
}

// Mock WordPress functions
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

/**
 * Simple XMLProcessor-based parser that just iterates through the file
 * to benchmark raw XMLProcessor performance
 */
class WXR_Parser_XMLProcessor_Simple {
    public function parse( $file ) {
        if ( ! file_exists( $file ) ) {
            return new WP_Error( 'WXR_parse_error', __( 'WXR file does not exist', 'wordpress-importer' ) );
        }

        $xml_content = file_get_contents( $file );
        if ( ! $xml_content ) {
            return new WP_Error( 'WXR_parse_error', __( 'Could not read WXR file', 'wordpress-importer' ) );
        }

        $processor = WordPress\XML\XMLProcessor::create_from_string( $xml_content );
        
        $tag_count = 0;
        $item_count = 0;
        $author_count = 0;
        $category_count = 0;
        $tag_count_wxr = 0;
        $term_count = 0;
        
        // Just iterate through all tokens to benchmark raw processing speed
        while ( $processor->next_token() ) {
            $tag_count++;
            
            if ( $processor->get_token_type() === '#tag' && ! $processor->is_tag_closer() ) {
                $tag_name = $processor->get_tag();
                
                switch ( $tag_name ) {
                    case 'item':
                        $item_count++;
                        break;
                    case 'wp:author':
                        $author_count++;
                        break;
                    case 'wp:category':
                        $category_count++;
                        break;
                    case 'wp:tag':
                        $tag_count_wxr++;
                        break;
                    case 'wp:term':
                        $term_count++;
                        break;
                }
            }
        }
        
        // Return minimal structure for compatibility
        return array(
            'authors'       => array_fill( 0, $author_count, array( 'author_login' => 'test' ) ),
            'posts'         => array_fill( 0, $item_count, array( 'post_title' => 'test' ) ),
            'categories'    => array_fill( 0, $category_count, array( 'cat_name' => 'test' ) ),
            'tags'          => array_fill( 0, $tag_count_wxr, array( 'tag_name' => 'test' ) ),
            'terms'         => array_fill( 0, $term_count, array( 'term_name' => 'test' ) ),
            'base_url'      => '',
            'base_blog_url' => '',
            'version'       => '1.1',
        );
    }
}

class WXRParserBenchmark {
    private $test_file;
    private $runs = 3;
    private $results = array();
    
    public function __construct( $test_file ) {
        $this->test_file = $test_file;
        
        if ( ! file_exists( $this->test_file ) ) {
            throw new Exception( "Test file not found: {$this->test_file}" );
        }
        
        echo "Benchmark Configuration:\n";
        echo "- Test file: {$this->test_file}\n";
        echo "- File size: " . $this->formatBytes( filesize( $this->test_file ) ) . "\n";
        echo "- Runs per parser: {$this->runs}\n";
        echo "- PHP Memory Limit: " . ini_get( 'memory_limit' ) . "\n";
        echo "- Max Execution Time: " . ini_get( 'max_execution_time' ) . "s\n\n";
    }
    
    public function run() {
        $parsers = array(
            'WXR_Parser_XMLProcessor_Simple' => new WXR_Parser_XMLProcessor_Simple(),
            'WXR_Parser_Entity_Reader'       => new WXR_Parser_Entity_Reader(),
            'WXR_Parser_XML'                 => new WXR_Parser_XML(),
            'WXR_Parser_Regex'               => new WXR_Parser_Regex(),
        );
        
        foreach ( $parsers as $name => $parser ) {
            echo "Testing {$name}...\n";
            $this->benchmarkParser( $name, $parser );
            echo "\n";
            
            // Force garbage collection between parsers
            gc_collect_cycles();
        }
        
        $this->displayResults();
    }
    
    private function benchmarkParser( $name, $parser ) {
        $times = array();
        $memory_peaks = array();
        $result_counts = array();
        
        for ( $i = 0; $i < $this->runs; $i++ ) {
            // Reset memory tracking
            $memory_start = memory_get_usage( true );
            $memory_peak_start = memory_get_peak_usage( true );
            
            // Time the parsing
            $start_time = microtime( true );
            $result = $parser->parse( $this->test_file );
            $end_time = microtime( true );
            
            $parse_time = $end_time - $start_time;
            $memory_used = memory_get_usage( true ) - $memory_start;
            $memory_peak = memory_get_peak_usage( true ) - $memory_peak_start;
            
            if ( is_wp_error( $result ) ) {
                echo "  Run " . ($i + 1) . ": ERROR - " . $result->get_error_message() . "\n";
                continue;
            }
            
            $times[] = $parse_time;
            $memory_peaks[] = $memory_peak;
            
            // Count results
            $counts = array(
                'authors' => count( $result['authors'] ),
                'posts' => count( $result['posts'] ),
                'categories' => count( $result['categories'] ),
                'tags' => count( $result['tags'] ),
                'terms' => count( $result['terms'] ),
            );
            $result_counts[] = $counts;
            
            echo "  Run " . ($i + 1) . ": {$this->formatTime($parse_time)} | Memory: {$this->formatBytes($memory_peak)} | Posts: {$counts['posts']}\n";
            
            // Force cleanup
            unset( $result );
            gc_collect_cycles();
        }
        
        if ( empty( $times ) ) {
            echo "  All runs failed!\n";
            return;
        }
        
        // Calculate statistics
        $avg_time = array_sum( $times ) / count( $times );
        $min_time = min( $times );
        $max_time = max( $times );
        $avg_memory = array_sum( $memory_peaks ) / count( $memory_peaks );
        
        $this->results[ $name ] = array(
            'avg_time' => $avg_time,
            'min_time' => $min_time,
            'max_time' => $max_time,
            'avg_memory' => $avg_memory,
            'runs' => count( $times ),
            'counts' => $result_counts[0] ?? array(), // Use first successful run's counts
        );
        
        echo "  Average: {$this->formatTime($avg_time)} | Memory: {$this->formatBytes($avg_memory)}\n";
    }
    
    private function displayResults() {
        echo str_repeat( "=", 80 ) . "\n";
        echo "BENCHMARK RESULTS\n";
        echo str_repeat( "=", 80 ) . "\n\n";
        
        if ( empty( $this->results ) ) {
            echo "No successful results to display.\n";
            return;
        }
        
        // Sort by average time
        uasort( $this->results, function( $a, $b ) {
            if ( $a['avg_time'] == $b['avg_time'] ) return 0;
            return $a['avg_time'] < $b['avg_time'] ? -1 : 1;
        });
        
        echo sprintf( "%-25s | %-12s | %-12s | %-12s | %-10s\n", 
            'Parser', 'Avg Time', 'Min Time', 'Max Time', 'Avg Memory' );
        echo str_repeat( "-", 80 ) . "\n";
        
        $fastest_time = null;
        foreach ( $this->results as $name => $data ) {
            if ( $fastest_time === null ) {
                $fastest_time = $data['avg_time'];
            }
            
            $relative_speed = $fastest_time > 0 ? $data['avg_time'] / $fastest_time : 1;
            $speed_indicator = $relative_speed > 1 ? sprintf( " (%.1fx slower)", $relative_speed ) : " (fastest)";
            
            echo sprintf( "%-25s | %-12s | %-12s | %-12s | %-10s%s\n",
                $name,
                $this->formatTime( $data['avg_time'] ),
                $this->formatTime( $data['min_time'] ),
                $this->formatTime( $data['max_time'] ),
                $this->formatBytes( $data['avg_memory'] ),
                $speed_indicator
            );
        }
        
        echo "\n";
        echo "Entity Counts (from first successful run):\n";
        echo str_repeat( "-", 50 ) . "\n";
        
        foreach ( $this->results as $name => $data ) {
            if ( ! empty( $data['counts'] ) ) {
                echo "{$name}:\n";
                foreach ( $data['counts'] as $type => $count ) {
                    echo "  {$type}: {$count}\n";
                }
                echo "\n";
                break; // Show counts from first parser only (should be same for all)
            }
        }
        
        // Performance analysis
        echo "Performance Analysis:\n";
        echo str_repeat( "-", 50 ) . "\n";
        
        $result_keys = array_keys( $this->results );
        $fastest = $result_keys[0];
        $slowest = end( $result_keys );
        
        if ( $fastest !== $slowest ) {
            $speed_diff = $this->results[ $slowest ]['avg_time'] / $this->results[ $fastest ]['avg_time'];
            echo "• {$fastest} is " . sprintf( "%.1f", $speed_diff ) . "x faster than {$slowest}\n";
        }
        
        // Memory analysis
        $memory_sorted = $this->results;
        uasort( $memory_sorted, function( $a, $b ) {
            if ( $a['avg_memory'] == $b['avg_memory'] ) return 0;
            return $a['avg_memory'] < $b['avg_memory'] ? -1 : 1;
        });
        
        $memory_keys = array_keys( $memory_sorted );
        $lowest_memory = $memory_keys[0];
        $highest_memory = end( $memory_keys );
        
        if ( $lowest_memory !== $highest_memory && $memory_sorted[ $lowest_memory ]['avg_memory'] > 0 ) {
            $memory_diff = $memory_sorted[ $highest_memory ]['avg_memory'] / $memory_sorted[ $lowest_memory ]['avg_memory'];
            echo "• {$lowest_memory} uses " . sprintf( "%.1f", $memory_diff ) . "x less memory than {$highest_memory}\n";
        }
    }
    
    private function formatTime( $seconds ) {
        if ( $seconds < 0.001 ) {
            return sprintf( "%.3fms", $seconds * 1000 );
        } elseif ( $seconds < 1 ) {
            return sprintf( "%.1fms", $seconds * 1000 );
        } else {
            return sprintf( "%.2fs", $seconds );
        }
    }
    
    private function formatBytes( $bytes ) {
        $units = array( 'B', 'KB', 'MB', 'GB' );
        $bytes = max( $bytes, 0 );
        $pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
        $pow = min( $pow, count( $units ) - 1 );
        
        $bytes /= ( 1 << ( 10 * $pow ) );
        
        return round( $bytes, 2 ) . ' ' . $units[ $pow ];
    }
}

// Check if WP_Error method exists
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

// Main execution
if ( ! empty( $argv[1] ) ) {
    $test_file = $argv[1];
} else {
    $test_file = __DIR__ . '/tests/wxrs/10MB.xml';
}

try {
    echo "WXR Parser Performance Benchmark\n";
    echo str_repeat( "=", 50 ) . "\n\n";
    
    $benchmark = new WXRParserBenchmark( $test_file );
    $benchmark->run();
    
} catch ( Exception $e ) {
    echo "Error: " . $e->getMessage() . "\n";
    exit( 1 );
}