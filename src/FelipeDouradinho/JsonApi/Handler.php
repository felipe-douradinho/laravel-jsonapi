<?php namespace FelipeDouradinho\JsonApi;

use Illuminate\Support\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Response as BaseResponse;
use Mockery\CountValidator\Exception;
use FelipeDouradinho\JsonApi\Exception as ApiException;

/**
 * Abstract class used to extend model API handlers from.
 *
 * @author Ronni Egeriis Persson <ronni@egeriis.me>
 */
abstract class Handler
{
    /**
     * Override this const in the extended to distinguish model handlers from each other.
     *
     * See under default error codes which bits are reserved.
     */
    const ERROR_SCOPE = 0;

    /**
     * Default error codes.
     */
    const ERROR_UNKNOWN_ID = 1;
    const ERROR_UNKNOWN_LINKED_RESOURCES = 2;
    const ERROR_NO_ID = 4;
    const ERROR_INVALID_ATTRS = 8;
    const ERROR_HTTP_METHOD_NOT_ALLOWED = 16;
    const ERROR_ID_PROVIDED_NOT_ALLOWED = 32;
    const ERROR_MISSING_DATA = 64;
    const ERROR_RESERVED_7 = 128;
    const ERROR_RESERVED_8 = 256;
    const ERROR_RESERVED_9 = 512;

    /**
     * Constructor.
     *
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Check whether a method is supported for a model.
     *
     * @param  string $method HTTP method
     * @return boolean
     */
    public function supportsMethod($method)
    {
        return method_exists($this, static::methodHandlerName($method));
    }

    /**
     * Fulfill the API request and return a response.
     *
     * @throws Exception if request method is not allowed
     * @return Response
     */
    public function fulfillRequest()
    {
        if (! $this->supportsMethod($this->request->method)) {
            throw new Exception(
                'Method not allowed',
                static::ERROR_SCOPE | static::ERROR_HTTP_METHOD_NOT_ALLOWED,
                BaseResponse::HTTP_METHOD_NOT_ALLOWED
            );
        }

        $methodName = static::methodHandlerName($this->request->method);
        $models = null;

        try
        {
            $models = $this->{$methodName}($this->request);
        }
        catch(ApiException $ex)
        {
            throw new ApiException();
        }

        if (is_null($models)) {
            $body = null;
            if (empty($this->request->id)) {
                $body = [];
            }
            return new Response($body, static::successfulHttpStatusCode($this->request->method));
        }
        
        if ($models instanceof Response) {
            $response = $models;
        } elseif ($models instanceof LengthAwarePaginator) {
            $items = new Collection($models->items());
            foreach ($items as $model) {
                $model->load($model->exposedRelations);
            }
            
            $response = new Response($items, static::successfulHttpStatusCode($this->request->method));
            
            $response->links = $this->getPaginationLinks($models);
            $response->included = $this->getIncludedModels($items);
            $response->errors = $this->getNonBreakingErrors();
        } else {

            if ($models instanceof Collection) {
                foreach ($models as $model) {
                    $model->load($model->exposedRelations);
                }
            } else {
                $models->load($models->exposedRelations);
            }
            
            $response = new Response($models, static::successfulHttpStatusCode($this->request->method));
        
            $response->included = $this->getIncludedModels($models);
            $response->errors = $this->getNonBreakingErrors();
        }

        return $response;
    }

    /**
     * Returns which requested linked resources are available.
     * @param array $exposedRelations
     * @return array
     */
    protected function exposedRelationsFromRequest($exposedRelations)
    {
        $available = [];
        foreach($this->request->include as $include) {
            $pieces = explode(".", $include);
            if(in_array($pieces[0], $exposedRelations)) {
                $available[] = $include;
            }
        }
        return $available;
    }

    /**
     * Returns which of the requested linked resources are not available.
     * @param array $exposedRelations
     * @return array
     */
    protected function unknownRelationsFromRequest($exposedRelations)
    {
        return array_diff($this->request->include, $exposedRelations);
    }

    /**
     * Iterate through result set to fetch the requested linked resources.
     *
     * @param  Illuminate\Database\Eloquent\Collection|JsonApi\Model $models
     * @return array
     */
    protected function getIncludedModels($models)
    {
        $links = new Collection();
        $models = $models instanceof Collection ? $models : [$models];

        foreach ($models as $model) {
            foreach ($this->exposedRelationsFromRequest($model->exposedRelations) as $relationName) {
                $relationshipNamePieces = explode(".", $relationName);
                $value = static::getModelsForRelation($model, $relationshipNamePieces[0]);

                if (is_null($value)) {
                    continue;
                }

                foreach ($value as $obj) {
                    $obj->load($obj->exposedRelations);
                    if(isset($relationshipNamePieces[1])) {
                        $subValue = static::getModelsForRelation($obj, $relationshipNamePieces[1]);
                        foreach ($subValue as $subObj) {
                            if(!$this->isModelInCollection($subObj, $links)) {
                                $links->push($subObj);
                            }
                        }
                    } else {
                        if(!$this->isModelInCollection($obj, $links)) {
                            $links->push($obj);
                        }
                    }
                }
            }
        }

        return $links->toArray();
    }

    /**
     * Checks if given model/result is in Collection already
     * by checking id and table.
     * @param Model $obj
     * @param Collection $links
     * @return bool
     */
    protected function isModelInCollection($obj, $links) {
        $items = $links->where('id', $obj->getKey());
        if (count($items) > 0) {
            foreach ($items as $item) {
                if ($item->getTable() === $obj->getTable()) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Return pagination links as array
     * @param Illuminate\Pagination\LengthAwarePaginator $paginator
     * @return array
     */
    protected function getPaginationLinks($paginator)
    {
        $links = [];
        
        $links['self'] = urldecode($paginator->url($paginator->currentPage()));
        $links['first'] = urldecode($paginator->url(1));
        $links['last'] = urldecode($paginator->url($paginator->lastPage()));
        
        $links['prev'] = urldecode($paginator->url($paginator->currentPage() - 1));
        if ($links['prev'] === $links['self'] || $links['prev'] === '') {
            $links['prev'] = null;
        }
        $links['next'] = urldecode($paginator->nextPageUrl());
        if ($links['next'] === $links['self'] || $links['next'] === '') {
            $links['next'] = null;
        }
        return $links;
    }

    /**
     * Return errors which did not prevent the API from returning a result set.
     *
     * @return array
     */
    protected function getNonBreakingErrors()
    {
        $errors = [];
        return $errors;
    }

    /**
     * A method for getting the proper HTTP status code for a successful request
     *
     * @param  string $method "PUT", "POST", "DELETE" or "GET"
     * @return int
     */
    public static function successfulHttpStatusCode($method)
    {
        switch ($method) {
            
            case 'POST':
                return BaseResponse::HTTP_CREATED;
            case 'DELETE':
                return BaseResponse::HTTP_NO_CONTENT;
            case 'GET':
            case 'PUT':
            case 'PATCH':
            case 'OPTIONS':
                return BaseResponse::HTTP_OK;
        }

        // Code shouldn't reach this point, but if it does we assume that the
        // client has made a bad request, e.g. PATCH
        return BaseResponse::HTTP_BAD_REQUEST;
    }

    /**
     * Convert HTTP method to it's handler method counterpart.
     *
     * @param  string $method HTTP method
     * @return string
     */
    protected static function methodHandlerName($method)
    {
        return 'handle' . ucfirst(strtolower($method));
    }

    /**
     * Returns the models from a relationship. Will always return as array.
     *
     * @param  Illuminate\Database\Eloquent\Model $model
     * @param  string $relationKey
     * @throws Exception if relationship does not exist
     * @return array|Illuminate\Database\Eloquent\Collection
     */
    protected static function getModelsForRelation($model, $relationKey)
    {
        if (!method_exists($model, $relationKey)) {
            throw new Exception(
                    'Relation "' . $relationKey . '" does not exist in model',
                    static::ERROR_SCOPE | static::ERROR_UNKNOWN_ID,
                    BaseResponse::HTTP_INTERNAL_SERVER_ERROR
                );
        }
        
        $relationModels = $model->{$relationKey};
        if (is_null($relationModels)) {
            return null;
        }

        if (! $relationModels instanceof Collection) {
            return new Collection([ $relationModels ]);
        }
        return $relationModels;
    }

    /**
     * This method returns the value from given array and key, and will create a
     * new Collection instance on the key if it doesn't already exist
     *
     * @param  array &$array
     * @param  string $key
     * @return Illuminate\Database\Eloquent\Collection
     */
    protected static function getCollectionOrCreate(&$array, $key)
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        return ($array[$key] = new Collection);
    }

    /**
     * The return value of this method will be used as the key to store the
     * linked model from a relationship. Per default it will return the plural
     * version of the relation name.
     * Override this method to map a relation name to a different key.
     *
     * @param  string $relationName
     * @return string
     */
    protected static function getModelNameForRelation($relationName)
    {
        return \str_plural($relationName);
    }
    
    /**
     * Function to handle sorting requests.
     *
     * @param  array $cols list of column names to sort on
     * @param  FelipeDouradinho\JsonApi\Model $model
     * @throws Exception if sort direction is not specified
     * @return FelipeDouradinho\JsonApi\Model
     */
    protected function handleSortRequest($cols, $model)
    {
        foreach ($cols as $col) {
            $directionSymbol = substr($col, 0, 1);
            if ($directionSymbol === "+" || substr($col, 0, 3) === '%2B') {
                $dir = 'asc';
            } elseif ($directionSymbol === "-") {
                $dir = 'desc';
            } else {
                throw new Exception(
                    'Sort direction not specified but is required. Expecting "+" or "-".',
                    static::ERROR_SCOPE | static::ERROR_UNKNOWN_ID,
                    BaseResponse::HTTP_BAD_REQUEST
                );
            }
            $col = substr($col, 1);
            $model = $model->orderBy($col, $dir);
        }
        return $model;
    }

    /**
     * Parses out any linkages in a resource object,
     * converting them into specific ids and/or an array of to-many relationships
     * @param array $resource
     * @return array
     */
    protected function parseResourceLinkage($resource)
    {
        $toManyRelationShips = [];

        foreach($resource['links'] as $key=>$link) {
            if($key !== 'self' && $key !== 'related' ) {
                if(isset($link['linkage'])) {
                    $linkage = $link['linkage'];
                    if(isset($linkage['id'])) {
                        //create id field from linkage
                        $resource[$key.'_id'] = $linkage['id'];
                    } else {
                        //pull out to-many relationships to be dealt with later.
                        foreach($linkage as $relation) {
                            if(!isset($toManyRelationShips[$key])) {
                                $toManyRelationShips[$key] = [];
                            }
                            $toManyRelationShips[$key][] = $relation;
                        }
                    }
                }
            }
        }

        if(!empty($toManyRelationShips)) {
            $resource['to-many'] = $toManyRelationShips;
        }
        return $resource;
    }
    
    /**
     * Parses and validates content from request into an array of
     * associative arrays representing database resources. Strips out
     * any properties that are not to be saved, like type and links.
     *
     * @param  string $content
     * @param  string $type the type the content is expected to be.
     * @throws Exception if request content is invalid
     * @throws Exception if missing 'type' parameter in $content
     * @throws Exception if 'type' parameter is invalid
     * @return array
     */
    protected function parseRequestContent($content, $type)
    {
        $content = json_decode($content, true);
        if (empty($content['data'])) {
            throw new Exception(
                'Payload either contains misformed JSON or missing "data" parameter.',
                static::ERROR_SCOPE | static::ERROR_INVALID_ATTRS,
                BaseResponse::HTTP_BAD_REQUEST
            );
        }
        
        $data = $content['data'];
        $resources = [];
        if(isset($data[0])) {
            $resources = $data;
        } else {
            $resources = [$data];
        }
        foreach($resources as $key => $resource) {
            if (!isset($resource['type'])) {
                throw new Exception(
                    '"type" parameter not set in request.',
                    static::ERROR_SCOPE | static::ERROR_INVALID_ATTRS,
                    BaseResponse::HTTP_BAD_REQUEST
                );
            }
            if ($resource['type'] !== $type) {
                throw new Exception(
                    '"type" parameter is not valid. Expecting ' . $type,
                    static::ERROR_SCOPE | static::ERROR_INVALID_ATTRS,
                    BaseResponse::HTTP_CONFLICT
                );
            }

            //convert links into ids
            if (isset($resource['links'])) {
                $resource = $this->parseResourceLinkage($resource);
                unset($resource['links']);
            }
            unset($resource['type']);
            $resources[$key] = $resource;
        }
        return $resources;
    }
    
    /**
     * Function to handle pagination requests.
     *
     * @param  FelipeDouradinho\JsonApi\Request $request
     * @param  FelipeDouradinho\JsonApi\Model $model
     * @param integer $total the total number of records
     * @return Illuminate\Pagination\LengthAwarePaginator
     */
    protected function handlePaginationRequest($request, $model, $total = null)
    {
        $page = $request->pageNumber;
        $perPage = $request->pageSize;
        if (!$total) {
            $total = $model->count();
        }
        $results = $model->forPage($page, $perPage)->get(array('*'));
        $paginator = new LengthAwarePaginator($results, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page[number]'
        ]);
        $paginator->appends('page[size]', $perPage);
        if (!empty($request->filter)) {
            foreach ($request->filter as $key=>$value) {
                $paginator->appends($key, $value);
            }
        }
        if (!empty($request->sort)) {
            $paginator->appends('sort', implode(',', $request->sort));
        }
        
        return $paginator;
    }
    
    /**
     * Function to handle filtering requests.
     *
     * @param  array $filters key=>value pairs of column and value to filter on
     * @param  FelipeDouradinho\JsonApi\Model $model
     * @return FelipeDouradinho\JsonApi\Model
     */
    protected function handleFilterRequest($filters, $model)
    {
        /**
         * Enables filtering array by "coma"
         *
         * @autor Felipe Douradinho
         * @autor-url http://www.douradinho.com/
         * @var  $key
         * @var  $value
         */
        foreach ($filters as $key=>$value)
        {
            if(is_array($value))
            {
                foreach($value as $val)
                {
                        $model = $model->orWhere($key, '=', $val);
                }
            }
            else
            {
                $model = $model->where($key, '=', $value);
            }
        }
        return $model;
    }

    /**
     * On OPTIONS requests, returns Allow header with the methods for current Handler.
     *
     * @param FelipeDouradinho\JsonApi\Request $request
     * @return FelipeDouradinho\JsonApi\Response
     */
    protected function handleOptions($request)
    {
        $allowedMethods = [];
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
        foreach ($methods as $method) {
            if ($this->supportsMethod($method)) {
                $allowedMethods[] = $method;
            }
        }

        $headers = ['Allow' => implode(',', $allowedMethods)];
        $response = new Response(null, static::successfulHttpStatusCode($this->request->method), $headers);
        return $response;
    }
    
    /**
     * Default handling of GET request.
     * Must be called explicitly in handleGet function.
     *
     * @param  FelipeDouradinho\JsonApi\Request $request
     * @param  FelipeDouradinho\JsonApi\Model $model
     * @throws Exception if database request fails
     * @return FelipeDouradinho\JsonApi\Model|Illuminate\Pagination\LengthAwarePaginator
     */
    protected function handleGetDefault($request, $model)
    {
        $total = null;
        if (empty($request->id)) {
            if (!empty($request->filter)) {
                $model = $this->handleFilterRequest($request->filter, $model);
            }
            if (!empty($request->sort)) {
                //if sorting AND paginating, get total count before sorting!
                if ($request->pageNumber) {
                    $total = $model->count();
                }
                $model = $this->handleSortRequest($request->sort, $model);
            }
        }
        
        try {
            if ($request->pageNumber && empty($request->id)) {
                $results = $this->handlePaginationRequest($request, $model, $total);
            } else if(!empty($request->id)) {
                $results = $model->find($request->id);
                if(!empty($request->relation)) {
                    if(!in_array($request->relation, $model->exposedRelations)) {
                        return null;
                    }
                    $results = $this->getModelsForRelation($results, $request->relation);
                }
            } else {
                $results = $model->get();
            }
        } catch (QueryException $e) {
            throw new Exception(
                'Database Request Failed',
                static::ERROR_SCOPE | static::ERROR_UNKNOWN_ID,
                BaseResponse::HTTP_INTERNAL_SERVER_ERROR,
                array('details' => $e->getMessage())
            );
        }

        return $results;
    }
    
    /**
     * Default handling of POST request.
     * Must be called explicitly in handlePost function.
     *
     * @param  FelipeDouradinho\JsonApi\Request $request
     * @param  FelipeDouradinho\JsonApi\Model $model
     * @throws Exception if database request fails
     * @return FelipeDouradinho\JsonApi\Model
     */
    public function handlePostDefault($request, $model)
    {
        $resources = $this->parseRequestContent($request->content, $model->getTable());

        foreach($resources as $resource) {
            if(isset($resource['to-many'])) {
                unset($resource['to-many']);
            }
            $model->fill($resource);

            if (!$model->save()) {
                throw new Exception(
                    'An unknown error occurred',
                    static::ERROR_SCOPE | static::ERROR_UNKNOWN_ID,
                    BaseResponse::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        }

        return $model;
    }

    /**
     * Default handling of PATCH request.
     * Must be called explicitly in handlePatch function.
     *
     * @param  FelipeDouradinho\JsonApi\Request $request
     * @param  FelipeDouradinho\JsonApi\Model $model
     * @throws Exception if database request fails
     * @return FelipeDouradinho\JsonApi\Model
     */
    public function handlePatchDefault($request, $model)
    {
        return $this->handlePutDefault($request, $model);
    }
    
    /**
     * Default handling of PUT request.
     * Must be called explicitly in handlePut function.
     *
     * @param  FelipeDouradinho\JsonApi\Request $request
     * @param  FelipeDouradinho\JsonApi\Model $model
     * @throws Exception if id is not set in request
     * @throws Exception if database request fails
     * @return FelipeDouradinho\JsonApi\Model
     */
    public function handlePutDefault($request, $model)
    {
        if (empty($request->id)) {
            throw new Exception(
                'No ID provided',
                static::ERROR_SCOPE | static::ERROR_NO_ID,
                BaseResponse::HTTP_BAD_REQUEST
            );
        }

        $model = $model::find($request->id);
        if (is_null($model)) {
            throw new Exception(
                'Could not find requested resource',
                static::ERROR_SCOPE | static::ERROR_MISSING_DATA,
                BaseResponse::HTTP_NOT_FOUND
            );
        }

        $resources = $this->parseRequestContent($request->content, $model->getTable());
        foreach($resources as $resource) {
            if(isset($resource['to-many'])) {
                $toManyRelationships = $resource['to-many'];
                //todo: update these relationships....
                unset($resource['to-many']);
            }
            $model->fill($resource);

            if (!$model->save()) {
                throw new Exception(
                    'An unknown error occurred',
                    static::ERROR_SCOPE | static::ERROR_UNKNOWN_ID,
                    BaseResponse::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        }
        
        return $model;
    }
    
    /**
     * Default handling of DELETE request.
     * Must be called explicitly in handleDelete function.
     *
     * @param  FelipeDouradinho\JsonApi\Request $request
     * @param  FelipeDouradinho\JsonApi\Model $model
     * @throws Exception if id is not set in request
     * @return FelipeDouradinho\JsonApi\Model
     */
    public function handleDeleteDefault($request, $model)
    {
        if (empty($request->id)) {
            throw new Exception(
                'No ID provided',
                static::ERROR_SCOPE | static::ERROR_NO_ID,
                BaseResponse::HTTP_BAD_REQUEST
            );
        }

        $model = $model::find($request->id);
        if (is_null($model)) {
            return null;
        }
        
        $model->delete();
        
        return $model;
    }
}
