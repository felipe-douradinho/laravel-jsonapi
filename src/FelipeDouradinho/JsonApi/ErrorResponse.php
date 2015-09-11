<?php namespace FelipeDouradinho\JsonApi;

use Illuminate\Http\JsonResponse;

/**
 * ErrorResponse represents a HTTP error response with a JSON API compliant payload.
 *
 * @author Ronni Egeriis Persson <ronni@egeriis.me>
 */
class ErrorResponse extends JsonResponse
{
    /**
     * Constructor.
     *
     * @param int    $httpStatusCode HTTP status code
     * @param mixed  $errorCode      Internal error code
     * @param string $errorTitle     Error description
     * @param array $additionalAttrs Any addition attributes to include in the response
     */
    public function __construct($httpStatusCode, $errorCode, $errorTitle, array $additionalAttrs = array())
    {
        $data = [
            'errors' => [ array_merge(
                [ 'status' => $httpStatusCode, 'code' => $errorCode, 'title' => $errorTitle ],
                $additionalAttrs
            ) ]
        ];
        parent::__construct($data, $httpStatusCode);
    }
}
