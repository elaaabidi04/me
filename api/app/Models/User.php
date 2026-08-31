<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // client | therapist
        'invite_code', // therapist-only, 2026-08-17 — see TherapistLinkController
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(Preference::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }

    public function dailyIntents(): HasMany
    {
        return $this->hasMany(DailyIntent::class);
    }

    // 2026-08-18: save/review/auto-confirm state for the daily breakdown
    // review feature -- see DailyReview's docblock and docs/dashboard-logic.md.
    public function dailyReviews(): HasMany
    {
        return $this->hasMany(DailyReview::class);
    }

    public function weeklyReflections(): HasMany
    {
        return $this->hasMany(WeeklyReflection::class);
    }

    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }

    public function canonEvents(): HasMany
    {
        return $this->hasMany(CanonEvent::class);
    }

    // 2026-08-17: role-aware sides of the therapist_client link (see
    // TherapistClient's docblock — a client has at most one active link,
    // hence hasOne rather than hasMany on that side; a therapist can have
    // many).
    public function linkedClients(): HasMany
    {
        return $this->hasMany(TherapistClient::class, 'therapist_id');
    }

    public function therapistLink(): HasOne
    {
        return $this->hasOne(TherapistClient::class, 'client_id');
    }

    public function safetyEvents(): HasMany
    {
        return $this->hasMany(SafetyEvent::class);
    }

    // 2026-08-18: "share to therapist" -- see InsightSnapshot's docblock.
    // Both live on the client side; a therapist reads a linked client's
    // shares through SharedInsightController::forClient(), not through
    // their own user row.
    public function insightSnapshots(): HasMany
    {
        return $this->hasMany(InsightSnapshot::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    // 2026-08-18: therapist -> client assigned tasks. Named by direction,
    // not just "tasks", since a therapist row and a client row both have
    // a legitimate but different relationship to the same table.
    public function tasksGiven(): HasMany
    {
        return $this->hasMany(AssignedTask::class, 'therapist_id');
    }

    public function tasksReceived(): HasMany
    {
        return $this->hasMany(AssignedTask::class, 'client_id');
    }

    // 2026-08-18: scheduled sessions, same therapist/client direction
    // split as tasksGiven/tasksReceived above.
    public function sessionsAsTherapist(): HasMany
    {
        return $this->hasMany(TherapySession::class, 'therapist_id');
    }

    public function sessionsAsClient(): HasMany
    {
        return $this->hasMany(TherapySession::class, 'client_id');
    }
}
