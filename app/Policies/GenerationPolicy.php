<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GenerationPolicy
{
    /**
     * Only the owner may see a generation. Others get a 404, so they can't
     * learn that it exists.
     */
    public function view(User $user, Generation $generation): Response
    {
        return $generation->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
