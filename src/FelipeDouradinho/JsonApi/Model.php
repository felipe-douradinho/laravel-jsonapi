<?php namespace FelipeDouradinho\JsonApi;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model as BaseModel;

/**
 * This class is used to extend models from, that will be exposed through
 * a JSON API.
 *
 * @author Ronni Egeriis Persson <ronni@egeriis.me>
 */
class Model extends \Eloquent
{
    /**
     * Let's guard these fields per default
     *
     * @var array
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * List of relations that can be included in response.
     * (eg. 'friend' could be included with ?include=friend)
     *
     * @var array
     */
    public $exposedRelations = [];

    /**
     * Convert the model instance to an array. This method overrides that of
     * Eloquent to prevent relations to be serialize into output array.
     *
     * @return array
     */
    public function toArray()
    {
        $relations = [];
        foreach ($this->getArrayableRelations() as $relation => $value) {
            if (in_array($relation, $this->hidden)) {
                continue;
            }

            if ($value instanceof BaseModel) {
                $relations[$relation] = array('linkage' => array('id' => $value->getKey(), 'type' => $value->getTable()));
				//$this->hidden[] = $relation . '_id'; // bug fix by Felipe Douradinho
            } elseif ($value instanceof Collection) {
                $relation = $relation;
                $items = [];
                foreach ($value as $item) {
                    $items[] = array('id' => $item->getKey(), 'type' => $item->getTable());
					$this->hidden[] = $relation . '_id';
                }
                $relations[$relation] = array('linkage' => $items);
            }
        }
        
        //add type parameter
        $attributes = $this->attributesToArray();
        $attributes['type'] = $this->getTable();

        if (! count($relations)) {
            return $attributes;
        }

        return array_merge(
            $attributes,
            [ 'links' => $relations ]
        );
    }
}
