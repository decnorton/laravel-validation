<?php namespace Dec\Validation;

class Model extends \Illuminate\Database\Eloquent\Model {

    /**
     * Array of related model names
     *
     * @var array
     */
    public static $relationships = [];

    /**
     * Validator instance
     *
     * @var Validator
     */
    protected $validator;

    /**
     * Hash instance
     *
     * @var Illuminate\Hashing\HasherInterface
     */
    protected $hash;

    /**
     * The rules to be applied to the data.
     *
     * @var array
     */
    public static $rules = [];

    /**
     * Errors
     *
     * @var \Illuminate\Support\MessageBag
     */
    public $errors;

    /**
     * Array of closure functions which determine if a given attribute is deemed
     * redundant (and should not be persisted in the database)
     *
     * @var array
     */
    protected $purgeFilters = [];
    protected $purgeFiltersInitialized = false;

    /**
     * List of attribute names which should be hashed using the Bcrypt hashing algorithm.
     *
     * @var array
     */
    public static $passwordAttributes = ['password'];

    public function __construct(array $attributes = array())
    {
        parent::__construct($attributes);

        $this->validator = new Validator(\App::make('validator'));

        $this->errors = new \Illuminate\Support\MessageBag;
        $this->hash = \App::make('hash');
    }

    /**
     * Attributes
     */

    public function getIsDeletedAttribute()
    {
        return (isset($softDelete) && $softDelete) && $this->deleted_at != null;
    }

    /**
     * Add the basic purge filters
     *
     * @return void
     */
    protected function addBasicPurgeFilters()
    {
        if ($this->purgeFiltersInitialized)
            return false;

        $this->purgeFilters[] = function ($attributeKey)
        {
            // disallow password confirmation fields
            if (ends_with($attributeKey, '_confirmation'))
            {
                return false;
            }

            // "_method" is used by Illuminate\Routing\Router to simulate custom HTTP verbs
            if (strcmp($attributeKey, '_method') === 0)
            {
                return false;
            }

            // "_token" is used by Illuminate\Html\FormBuilder to add CSRF protection
            if (strcmp($attributeKey, '_token') === 0)
            {
                return false;
            }

            return true;
        };

        $this->purgeFiltersInitialized = true;
    }

    /**
     * Removes redundant attributes from model
     *
     * @param array $array Input array
     * @return array
     */
    protected function purgeArray(array $array = array())
    {

        $result = array();
        $keys = array_keys($array);

        $this->addBasicPurgeFilters();

        if (!empty($keys) && !empty($this->purgeFilters))
        {
            foreach ($keys as $key)
            {
                $allowed = true;

                foreach ($this->purgeFilters as $filter)
                {
                    $allowed = $filter($key);

                    if (!$allowed)
                    {
                        break;
                    }
                }

                if ($allowed)
                {
                    $result[$key] = $array[$key];
                }
            }
        }

        return $result;
    }

    /**
     * Automatically replaces all plain-text password attributes (listed in $passwordAttributes)
     * with hash checksum.
     *
     * @param array $attributes
     * @param array $passwordAttributes
     * @return array
     */
    protected function hashPasswordAttributes(array $attributes = array(), array $passwordAttributes = array())
    {

        if (empty($passwordAttributes) || empty($attributes))
            return $attributes;

        $result = array();
        foreach ($attributes as $key => $value)
        {

            if (
                in_array($key, $passwordAttributes)
                && !is_null($value)
                && $value != $this->getOriginal($key)
            )
                $result[$key] = $this->hash->make($value);
            else
                $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Called before model create operations.
     *
     * @return boolean      Should continue creation
     */
    public function beforePerformInsert()
    {
        return $this->isValid();
    }

    /**
     * Called before model update operations.
     *
     * @return boolean      Should continue update
     */
    public function beforePerformUpdate()
    {
        // Validate
        $rules = $this->buildUpdateRules(static::$rules);

        return $this->isValid($rules);
    }

    /**
     * Prepare attributes for insertion into database
     *
     * @return void
     */
    protected function prepareAttributes()
    {
        // Remove redundant attributes
        $this->attributes = $this->purgeArray($this->getAttributes());

        // Hash password attributes
        $this->attributes = $this->hashPasswordAttributes($this->getAttributes(), static::$passwordAttributes);
    }

    /**
     * Perform a model update operation.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return bool
     */
    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        if (!$this->beforePerformUpdate())
            return false;

        $this->prepareAttributes();

        return parent::performUpdate($query);
    }

    /**
     * Perform a model insert operation.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return bool
     */
    protected function performInsert(\Illuminate\Database\Eloquent\Builder $query)
    {
        if (!$this->beforePerformInsert())
            return false;

        $this->prepareAttributes();

        return parent::performInsert($query);
    }

    /**
     * Errors
     */

    public function addError($key, $value)
    {
        $this->errors()->add($key, $value);
    }

    public function addErrors($errors)
    {
        if (is_a($errors, '\Illuminate\Support\MessageBag'))
        {
            $errors = $errors->toArray();
        }

        $this->errors()->merge($errors);
    }

    public function errors()
    {
        return $this->errors;
    }

    /**
     * Validation
     */

    public function validate(array $rules = array())
    {
        $rules = !empty($rules) ? $rules : static::$rules;

        if (!$success = $this->validator->validate($this->getAttributes(), $rules))
            $this->addErrors($this->validator->errors());

        return $success;
    }

    public function isValid(array $rules = array())
    {
        return $this->validate($rules);
    }

    protected function buildUpdateRules(array $rules = array())
    {
        return $this->buildUniqueExclusionRules($rules);
    }

    /**
     * When given an ID and a Laravel validation rules array, this function
     * appends the ID to the 'unique' rules given. The resulting array can
     * then be fed to a Ardent save so that unchanged values
     * don't flag a validation issue. Rules can be in either strings
     * with pipes or arrays, but the returned rules are in arrays.
     *
     * @param int   $id
     * @param array $rules
     * @return array Rules with exclusions applied
     */
    protected function buildUniqueExclusionRules(array $rules = array())
    {
        if (!count($rules))
          $rules = static::$rules;

        foreach ($rules as $field => &$ruleset)
        {
            // If $ruleset is a pipe-separated string, switch it to array
            $ruleset = (is_string($ruleset))? explode('|', $ruleset) : $ruleset;

            foreach ($ruleset as &$rule)
            {
              if (strpos($rule, 'unique') === 0)
              {
                $params = explode(',', $rule);

                $uniqueRules = array();

                // Append table name if needed
                $table = explode(':', $params[0]);
                if (count($table) == 1)
                  $uniqueRules[1] = $this->table;
                else
                  $uniqueRules[1] = $table[1];

                // Append field name if needed
                if (count($params) == 1)
                    $uniqueRules[2] = $field;
                else
                    $uniqueRules[2] = $params[1];

                if (isset($this->primaryKey))
                {
                    $uniqueRules[3] = $this->{$this->primaryKey};
                    $uniqueRules[4] = $this->primaryKey;
                }
                else {
                  $uniqueRules[3] = $this->id;
                }

                $rule = 'unique:' . implode(',', $uniqueRules);
              } // end if strpos unique

            } // end foreach ruleset
        }

        return $rules;
    }

}