<?php namespace Dec\Validation;

class ValidationException extends \Exception {

    /**
     * @var \Illuminate\Support\MessageBag
     */
    protected $errors;

    public function __construct($message, \Illuminate\Support\MessageBag $errors)
    {
        $this->errors = $errors;

        parent::__construct($message);
    }

    public function errors()
    {
        return $this->errors;
    }

}