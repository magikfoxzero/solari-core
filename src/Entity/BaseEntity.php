<?php

namespace NewSolari\Core\Entity;

use NewSolari\Core\Entity\Traits\BelongsToPartition;
use NewSolari\Core\Entity\Traits\SoftDeleteCascade;
use NewSolari\Core\Entity\Traits\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

abstract class BaseEntity extends Model
{
    use SoftDeletes, SoftDeleteCascade, BelongsToPartition;
    /**
     * The attributes that should be encrypted.
     *
     * @var array
     */
    protected $encrypted = [];

    /**
     * The validation rules for the entity.
     *
     * @var array
     */
    protected $validations = [];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'record_id';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically generate UUID for record_id if not set
        static::creating(function ($model) {
            $keyName = $model->getKeyName();
            // Only auto-generate UUID for single primary keys, not composite keys
            if (is_string($keyName) && empty($model->{$keyName})) {
                $model->{$keyName} = (string) Str::uuid();
            }
        });
    }

    /**
     * Create a new entity with validation.
     *
     * @return static
     *
     * @throws ValidationException
     */
    public static function createWithValidation(array $data)
    {
        $instance = new static;

        // Ensure record_id is set before validation
        if (empty($data['record_id'])) {
            $data['record_id'] = (string) Str::uuid();
        }

        // Validate the data
        $instance->validateData($data);

        // NOTE: Do NOT call encryptFields() here - setAttribute() handles encryption
        // to prevent double encryption when create() calls fill() -> setAttribute()

        return static::create($data);
    }

    /**
     * Update the entity with validation.
     *
     * @return bool
     *
     * @throws ValidationException
     */
    public function updateWithValidation(array $data)
    {
        // Validate the data
        $this->validateData($data);

        // NOTE: Do NOT call encryptFields() here - setAttribute() handles encryption
        // to prevent double encryption when update() calls fill() -> setAttribute()

        return $this->update($data);
    }

    /**
     * Validate the given data.
     *
     * @throws ValidationException
     */
    protected function validateData(array $data)
    {
        if (! empty($this->validations)) {
            $validator = Validator::make($data, $this->validations);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        }
    }

    /**
     * Encrypt sensitive fields.
     *
     * @return array
     */
    protected function encryptFields(array $data)
    {
        foreach ($this->encrypted as $field) {
            if (isset($data[$field])) {
                $data[$field] = Crypt::encryptString($data[$field]);
            }
        }

        return $data;
    }

    /**
     * Decrypt sensitive fields.
     *
     * @param  string  $value
     * @return string|null
     */
    protected function decryptField($value)
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Get the encrypted attribute.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if (in_array($key, $this->encrypted) && $value !== null) {
            return $this->decryptField($value);
        }

        return $value;
    }

    /**
     * Set the encrypted attribute.
     *
     * @param  string  $key
     * @param  mixed  $value
     */
    public function setAttribute($key, $value)
    {
        // Only encrypt non-null values for encrypted fields
        if (in_array($key, $this->encrypted) && $value !== null) {
            $value = Crypt::encryptString($value);
        }

        parent::setAttribute($key, $value);
    }

    /**
     * Delete the entity with validation (soft delete).
     *
     * Sets the 'deleted' flag to true instead of removing the record.
     *
     * @param  string|null  $deletedBy  User ID who is deleting (for audit trail)
     * @return bool
     *
     * @throws \Exception
     */
    public function deleteWithValidation(?string $deletedBy = null)
    {
        // Check if deletion is allowed
        if (method_exists($this, 'canDelete') && ! $this->canDelete()) {
            throw new \Exception('Deletion not allowed for this entity');
        }

        // Set deleted_by if the column exists and deletedBy is provided
        if ($deletedBy && in_array('deleted_by', $this->getFillable())) {
            $this->deleted_by = $deletedBy;
        }

        return $this->delete(); // Uses SoftDeletes trait - sets deleted = true
    }

    /**
     * Check if the current user has permission to perform an action.
     *
     * @param  string  $action
     * @param  string|null  $userId
     * @return bool
     */
    public function checkPermission($action, $userId = null)
    {
        if (method_exists($this, 'hasPermission')) {
            return $this->hasPermission($action, $userId);
        }

        return true; // Default to true if no permission method exists
    }
}
