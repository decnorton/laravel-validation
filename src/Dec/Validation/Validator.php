<?php namespace Dec\Validation;

use Illuminate\Validation\Factory;

class Validator {

    /**
     * @var Illuminate\Validation\Factory
     */
    protected $validator;

    protected $validation;

    protected $rules = array();

    public function __construct(\Illuminate\Validation\Factory $validator)
    {
        $this->messages = new \Illuminate\Support\MessageBag;
        $this->validator = $validator;
    }

    public function validate(array $data, array $rules = array())
    {
        $rules = !empty($rules) ? $rules : $this->rules;

        if ($rules === null)
            throw new \InvalidArgumentException('Missing validation rules');

        if (!count($rules))
            // No rules, skip validation
            return true;

        $this->validation = $this->validator->make(
            $data,
            $rules
        );

        return $this->validation->passes();
    }

    public function errors()
    {
        return $this->validation ? $this->validation->errors() : null;
    }

}