<?php
namespace AQNEX\Models;

class User
{
    public int $id;
    public string $username;
    public string $full_name;
    public string $position;
    public ?string $custom_permissions;

    public function __construct(array $data = [])
    {
        $this->id = intval($data['userid'] ?? 0);
        $this->username = (string)($data['username'] ?? '');
        $this->full_name = (string)($data['full_name'] ?? $this->username);
        $this->position = (string)($data['position'] ?? '');
        $this->custom_permissions = $data['custom_permissions'] ?? null;
    }
}
