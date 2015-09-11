<?php namespace FelipeDouradinho\JsonApi;

/**
 * A class used to represented a client request to the API.
 *
 * @author Ronni Egeriis Persson <ronni@egeriis.me>
 */
class Request
{
    
    /**
     * Contains the url of the request
     *
     * @var string
     */
    public $url;
    
    /**
     * Contains the HTTP method of the request
     *
     * @var string
     */
    public $method;

    /**
     * Contains an optional model ID from the request
     *
     * @var int
     */
    public $id;
    
    /**
     * Contains any content in request
     *
     * @var string
     */
    public $content;
    
    /**
     * Contains an array of linked resource collections to load
     *
     * @var array
     */
    public $include;
    
    /**
     * Contains an array of column names to sort on
     *
     * @var array
     */
    public $sort;
    
    /**
     * Contains an array of key/value pairs to filter on
     *
     * @var array
     */
    public $filter;
    
    /**
     * Specifies the page number to return results for
     * @var integer
     */
    public $pageNumber;
    
    /**
     * Specifies the number of results to return per page. Only used if
     * pagination is requested (ie. pageNumber is not null)
     *
     * @var integer
     */
    public $pageSize = 50;

    /**
     * Specifies a relation
     * @var string
     */
    public $relation;
	
	/**
     * Specifies the resource fields, keyed by type, to return.
     * @var array
     */
    public $fields;

    /**
     * Constructor.
     *
     * @param string $url the full URL of the request
     * @param string $method the HTTP method of the request
     * @param int    $id optional an id of a specific requested resource
     * @param mixed  $content optional content sent with the request, like data sent via POST or PUT
     * @param array  $include optional list of related resources to load along with main results
     * @param array  $sort optional list of fields to sort on
     * @param array  $filter optional list of fields and values to filter on
     * @param array  $page optional an array containing size and number to define pagination
     * @param string $relation optional a name of a related resource to load
	 * @param array  $fields optional list of specific fields to return in the results, keyed by type.
     */
    public function __construct(
      $url, $method, $id = null, $content = null,
      array $include = [], array $sort = [], array $filter = [],
      array $page = [], $relation = null, array $fields = []
    ) {
        $this->url = $url;
        $this->method = $method;
        $this->id = $id;
        $this->content = $content;
        $this->include = $include ?: [];
        $this->sort = $sort ?: [];
        $this->filter = $filter ?: [];
        $this->relation = $relation;
        $this->fields = $fields;

        $pageSize = null;
        $pageNumber = null;
        if($page) {
            if(!empty($page['size']) && !empty($page['number'])) {
                $pageSize = $page['size'];
                $pageNumber = $page['number'];
            }
        }
        
        $this->pageNumber = $pageNumber ?: null;
        if ($pageSize) {
            $this->pageSize = $pageSize;
        }
    }
}
