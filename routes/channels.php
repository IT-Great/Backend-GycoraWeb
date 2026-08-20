<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Broadcast::channel('chat.{id}', function ($user, $id) {
//     return (int) $user->id === (int) $id;
// });

Broadcast::channel('chat.{id}', function ($user, $id) {
    // [PERBAIKAN] Izinkan akses JIKA user adalah pemilik ID tersebut, 
    // ATAU JIKA user tersebut adalah jajaran Admin / CS Gycora.
    return (int) $user->id === (int) $id || in_array($user->usertype, ['admin', 'superadmin', 'cs']);
});
