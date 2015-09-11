JSON API helpers for Laravel 5
=====

[![Build Status](https://travis-ci.org/FelipeDouradinho/laravel-jsonapi.svg?branch=master)](https://travis-ci.org/FelipeDouradinho/laravel-jsonapi)

Make it a breeze to create a [jsonapi.org](http://jsonapi.org/) RC3 compliant API with Laravel 5. 

Code forked from echo-it/laravel-jsonapi project by Ronni Egeriis Persson.

Installation
-----

1. Add `felipe-douradinho/laravel-jsonapi` to your composer.json dependency list

3. Run `composer update`.

### Requirements

* PHP 5.4+
* Laravel 5.1.*

Using laravel-jsonapi
-----

This library is made with the concept of exposing models in mind, as found in the RESTful API approach.

In few steps you can expose your models:

1. **Create a route to direct the requests**

    In this example, we use a route for any OPTION requests, a generic route for interacting with resources, and another route for interacting with resource relationships:

    ```php
	Route::options('api/{model}/{id?}', 'ApiController@handleRequest');
	Route::any('api/{model}/{id?}', 'ApiController@handleRequest');
	Route::any('api/{model}/{id}/links/{relation}', 'ApiController@handleRequest');
    ```

2. **Create your controller to handle the request**

    Your controller is responsible to handling input, instantiating a handler class and returning the response.

    ```php
		 <?php namespace App\Http\Controllers;
		use FelipeDouradinho\JsonApi\Request as ApiRequest;
		use FelipeDouradinho\JsonApi\ErrorResponse as ApiErrorResponse;
		use FelipeDouradinho\JsonApi\Exception as ApiException;
		use Request;

		class ApiController extends Controller
		{
			public function handleRequest($modelName, $id = null, $relation = null)
			{
				/**
				 * Create handler name from model name
				 * @var string
				 */
				$handlerClass = 'App\\Handlers\\' . ucfirst($modelName) . 'Handler';

				if (class_exists($handlerClass)) {
					$url = Request::url();
					$method = Request::method();
					$include = ($i = Request::input('include')) ? explode(',', $i) : [];
					$sort = ($i = Request::input('sort')) ? explode(',', $i) : [];
					$filter = ($i = Request::except('sort', 'include', 'page')) ? $i : [];
					$content = Request::getContent();

					$page = ($i = Request::input('page')) ? $i : [];
					if (!empty($page) && (!is_array($page) || empty($page['size']) || empty($page['number']))) {
						return new ApiErrorResponse(400, 400, 'Expected page[size] and page[number]');
					}

					$request = new ApiRequest(Request::url(), $method, $id, $content, $include, $sort, $filter, $page, $relation);
					$handler = new $handlerClass($request);

					// A handler can throw EchoIt\JsonApi\Exception which must be gracefully handled to give proper response
					try {
						$res = $handler->fulfillRequest();
					} catch (ApiException $e) {
						return $e->response();
					}
					
					return $res->toJsonResponse();
				}

				// If a handler class does not exist for requested model, it is not considered to be exposed in the API
				return new ApiErrorResponse(404, 404, 'Entity not found');
			}
		}
    ```

3. **Create a handler for your model**

    A handler is responsible for exposing a single model.

    In this example we have create a handler which supports the following requests:

    * GET /users (ie. handleGet function)
    * GET /users/[id] (ie. handleGet function)
    * PATCH /users/[id] (ie. handlePatch function)
    
    Requests are automatically routed to appropriate handle functions.

    ```php
        <?php namespace App\Handlers;
      
          use Symfony\Component\HttpFoundation\Response;
          use App\Models\User;
          
          use FelipeDouradinho\JsonApi\Exception as ApiException;
          use FelipeDouradinho\JsonApi\Request as ApiRequest;
          use FelipeDouradinho\JsonApi\Handler as ApiHandler;
          use Request;
          
          /**
           * Handles API requests for Users.
           */
          class UsersHandler extends ApiHandler
          {
              const ERROR_SCOPE = 1024;
              
              /**
               * Handles GET requests. 
               * @param FelipeDouradinho\JsonApi\Request $request
               * @return FelipeDouradinho\JsonApi\Model|Illuminate\Support\Collection|FelipeDouradinho\JsonApi\Response|Illuminate\Pagination\LengthAwarePaginator
               */
              public function handleGet(ApiRequest $request)
              {
                  //you can use the default GET functionality, or override with your own 
                  return $this->handleGetDefault($request, new User);
              }
              
              /**
               * Handles PATCH requests. 
               * @param FelipeDouradinho\JsonApi\Request $request
               * @return FelipeDouradinho\JsonApi\Model|Illuminate\Support\Collection|FelipeDouradinho\JsonApi\Response
               */
              public function handlePatch(ApiRequest $request)
              {
                  //you can use the default PATCH functionality, or override with your own
                  return $this->handlePatchDefault($request, new User);
              }
          }
    ```



**Note:** Extend your models from `FelipeDouradinho\JsonApi\Model` rather than `Eloquent` to get the proper response for linked resources. In your model, you can define which relationships should be exposed: 

```php
	<?php namespace App\Models;

	use FelipeDouradinho\JsonApi\Model as ApiModel;
	
	class User extends ApiModel {
		
		public $exposedRelations = ['friends'];
	
		public function friends()
		{
		    return $this->hasMany('App\Models\Friend');
		}
	}
```

Current features
-----

According to [jsonapi.org](http://jsonapi.org):

* [Resource Representations](http://jsonapi.org/format/#document-structure-resource-representations) as resource objects
* [Resource Relationships](http://jsonapi.org/format/#document-structure-resource-relationships)
* [Relationship URLs](http://jsonapi.org/format/#document-structure-resource-relationships)  e.g. /users/[id]/links/friends
* [Compound Documents](http://jsonapi.org/format/#document-structure-compound-documents)
* [Sorting](http://jsonapi.org/format/#fetching-sorting)
* [Filtering](http://jsonapi.org/format/#fetching-filtering) (Note: Doesn't use FILTER keyword. An example: /users?name=Joe)
* [Pagination](http://jsonapi.org/format/#fetching-pagination)

The features in the Handler class are each in their own function (eg. handlePaginationRequest, handleSortRequest, etc.), so you can easily override them with your own behaviour if desired. 
	

Wishlist
-----

* [Resource URLs](http://jsonapi.org/format/#document-structure-resource-urls)
* [Updating Relationships](http://jsonapi.org/format/#crud-updating-relationships)
* [Sparse Fieldsets](http://jsonapi.org/format/#fetching-sparse-fieldsets)
* Strict checking of application/vnd.api+json in content-type and Accept Headers
