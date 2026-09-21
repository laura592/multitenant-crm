<?php

namespace App\Policies;

use App\Models\OffertaCaffe;
use App\Models\User;

/**
 * Le offerte caffe' le fa chi legge il listino caffe': gli stessi permessi,
 * cosi' non servono permessi nuovi da assegnare a mano ai ruoli. Oggi sono
 * admin, amministratore e amministrazione; dipendenti e partner no.
 */
class OffertaCaffePolicy
{
    private function puo(User $user): bool
    {
        return $user->can('view_any_prodotto::caffe');
    }

    public function viewAny(User $user): bool
    {
        return $this->puo($user);
    }

    public function view(User $user, OffertaCaffe $offerta): bool
    {
        return $this->puo($user);
    }

    public function create(User $user): bool
    {
        return $this->puo($user);
    }

    public function update(User $user, OffertaCaffe $offerta): bool
    {
        return $this->puo($user);
    }

    public function delete(User $user, OffertaCaffe $offerta): bool
    {
        return $this->puo($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->puo($user);
    }
}
