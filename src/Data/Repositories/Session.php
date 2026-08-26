<?php

namespace PragmaRX\Tracker\Data\Repositories;

use Carbon\Carbon;
use PragmaRX\Support\Config;

class Session extends Repository
{
    private $config;

    private $sessionInfo;
    
    private static array $memorySessionStore = [];

    protected $relations = ['device', 'user', 'log', 'language', 'agent', 'referer', 'geoIp', 'cookie'];

    public function __construct($model, Config $config, $session = null)
    {
        $this->config = $config;

        parent::__construct($model);
    }

    public function findByUuid($uuid)
    {
        if (empty($uuid)) {
            return null;
        }

        return $this->newQuery()->where('uuid', $uuid)->first();
        [$model, $cacheKey] = $this->cache->findCached($uuid, 'uuid', app()->make('tracker.config')->get('session_model'));
        \Illuminate\Support\Facades\Log::info("model");
        \Illuminate\Support\Facades\Log::info($model);
        \Illuminate\Support\Facades\Log::info("cacheKey");
        \Illuminate\Support\Facades\Log::info($cacheKey);

        if (!$model) {
            $model = $this->newQuery()->where('uuid', $uuid)->first();

            if ($model) {
                $this->cache->cachePut($cacheKey, $model);
            }
        }

        return $model;
    }

    public function getCurrentId($sessionInfo)
    {
        $this->setSessionData($sessionInfo);

        return $this->sessionGetId();
    }

    public function setSessionData($sessionInfo)
    {
        $this->generateSession($sessionInfo);

        if ($this->sessionIsKnownOrCreateSession()) {
            $this->ensureSessionDataIsComplete();
        }
    }

    private function generateSession($sessionInfo)
    {
        $this->sessionInfo = $sessionInfo;

        if (!$this->sessionIsReliable()) {
            $this->regenerateSystemSession();
        }

        $this->checkSessionUuid();
    }

    private function sessionIsReliable()
    {
        $data = $this->getSessionData();

        if (isset($data['user_id']) && isset($data['user_type'])) {
            if ($data['user_id'] !== $this->sessionInfo['user_id'] && $data['user_type'] !== $this->sessionInfo['user_type']) {
                return false;
            }
        }

        if (isset($data['client_ip'])) {
            if ($data['client_ip'] !== $this->sessionInfo['client_ip']) {
                return false;
            }
        }

        if (isset($data['user_agent'])) {
            if ($data['user_agent'] !== $this->sessionInfo['user_agent']) {
                return false;
            }
        }

        return true;
    }

    private function sessionIsKnownOrCreateSession()
    {
        $uuid = $this->getSystemSessionId();
        $session = $this->findByUuid($uuid);

        if (!$session) {
            $id = $this->findOrCreate($this->sessionInfo, ['uuid']);
            $this->sessionSetId($id);
            return false;
        }

        $id = $session->getKey();
        $this->sessionSetId($id);

        $updatedAt = $session->updated_at;
        $needsUpdate = !$updatedAt || Carbon::parse($updatedAt)->lte(Carbon::now()->subMinutes(1));

        if ($needsUpdate) {
            $session->updated_at = Carbon::now();
            $session->exists = true;

            $session->save();
        }

        return true;
    }

    private function sessionIsKnown()
    {   
        $uuid = $this->getSystemSessionId();
    
        return $this->findByUuid($uuid) !== null;
    }

    private function ensureSessionDataIsComplete()
    {
        $sessionData = $this->getSessionData();

        if (empty($sessionData) || empty($this->sessionInfo['id'])) {
            return;
        }

        $wasComplete = true;

        foreach ($this->sessionInfo as $key => $value) {
            if (in_array($key, ['user_agent', 'impersonation_id'])) {
                continue;
            }
            if (isset($sessionData[$key]) && $sessionData[$key] !== $value) {
                if (!isset($model)) {
                    $model = $this->find($this->sessionInfo['id']);
                }

                if ($model && ($key !== 'user_id' || !is_null($value))) {
                    $model->setAttribute($key, $value);
                    $model->save();
                }

                $wasComplete = false;
            }
        }

        if (!$wasComplete) {
            $this->storeSession();
        }
    }

    private function sessionGetId()
    {
        return $this->sessionInfo['id'] ?? null;
    }

    private function sessionSetId($id)
    {
        $this->sessionInfo['id'] = $id;

        $this->storeSession();
    }

    private function storeSession()
    {
        $this->putSessionData($this->sessionInfo);
    }

    private function getSystemSessionId()
    {
        $request = request();

        if ($request && ($token = $request->bearerToken())) {
            return hash('sha256', $token);
        }

        $ip = $request ? ($request->ip() ?? '0.0.0.0') : '0.0.0.0';
        $agent = $request ? ($request->userAgent() ?? 'unknown') : 'unknown';
        $date = date('Y-m-d');

        return md5("{$ip}|{$agent}|{$date}");
    }

    private function regenerateSystemSession($data = null)
    {
        $data = $data ?: $this->getSessionData();

        if (!$data || ($data['user_id'] != $this->sessionInfo['user_id'] && $data['user_type'] != $this->sessionInfo['user_type'])) {
            $this->resetSessionUuid($data);

            $this->sessionIsKnownOrCreateSession();
        }

        return $this->sessionInfo;
    }

    private function getSessionData($variable = null)
    {
        $data = static::$memorySessionStore;

        return $variable ? ($data[$variable] ?? null) : $data;
    }

    private function putSessionData($data)
    {
        static::$memorySessionStore = $data;
    }

    private function getSessionKey()
    {
        return $this->config->get('tracker_session_name');
    }

    private function getSessions()
    {
        return $this
            ->newQuery()
            ->with($this->relations)
            ->orderBy('updated_at', 'desc');
    }

    public function all()
    {
        return $this->getSessions()->get();
    }

    public function last($minutes, $returnResults)
    {
        $query = $this
            ->getSessions()
            ->period($minutes);

        if ($returnResults) {
            $cacheKey = 'last-sessions';

            $result = $this->cache->findCachedWithKey($cacheKey);

            if (!$result) {
                $result = $query->get();

                $this->cache->cachePut($cacheKey, $result, 1);

                return $result;
            }

            return $result;
        }

        return $query;
    }

    public function userDevices($minutes, $user_id, $user_type, $results)
    {
        if (!$user_id) {
            return [];
        }

        $sessions = $this
            ->getSessions()
            ->period($minutes)
            ->where('user_id', $user_id)
            ->where('user_type', $user_type);

        if ($results) {
            $sessions = $sessions->get()->pluck('device')->unique();
        }

        return $sessions;
    }

    public function users($minutes, $results)
    {
        return $this->getModel()->users($minutes, $results);
    }

    public function getCurrent()
    {
        return $this->getModel();
    }

    public function updateSessionData($data)
    {
        $session = $this->checkIfUserChanged($data, $this->find($this->getSessionData('id')));

        if ($session) {
            foreach ($session->getAttributes() as $name => $value) {
                if (isset($data[$name]) && $name !== 'id' && $name !== 'uuid') {
                    $session->{$name} = $data[$name];
                }
            }

            $session->save();
        }

        return $data;
    }

    private function checkIfUserChanged($data, $model)
    {
        if ($model && !is_null($model->user_id) && !is_null($data['user_id']) && $data['user_id'] !== $model->user_id) {
            $newSession = $this->regenerateSystemSession($data);

            $model = $this->findByUuid($newSession['uuid']);
        }

        return $model;
    }

    private function checkSessionUuid()
    {
        if (!isset($this->sessionInfo['uuid']) || !$this->sessionInfo['uuid']) {
            $this->sessionInfo['uuid'] = $this->getSystemSessionId();
        }
    }

    private function resetSessionUuid($data = null)
    {
        $this->sessionInfo['uuid'] = null;

        $data = $data ?: $this->sessionInfo;

        unset($data['uuid']);

        $this->putSessionData($data);

        $this->checkSessionUuid();

        return $data;
    }
}
