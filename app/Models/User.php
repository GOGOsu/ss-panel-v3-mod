<?php

namespace App\Models;

/**
 * User Model
 */

use App\Utils\Tools;
use App\Utils\Hash;
use App\Models\InviteCode;
use App\Services\Config;
use App\Utils\GA;
use App\Models\Link;
use App\Utils\Wecenter;
use App\Utils\Radius;

class User extends Model
{
    protected $connection = "default";
    protected $table = "user";

    public $isLogin;

    public $isAdmin;
    
    protected $casts = [
        "t" => 'int',
        "u" => 'int',
        "d" => 'int',
        "port" => 'int',
        "transfer_enable" => 'float',
        "enable" => 'int',
        'is_admin' => 'boolean',
        'is_multi_user' => 'int',
        'node_speedlimit' => 'float',
    ];

    public function getGravatarAttribute()
    {
        $hash = md5(strtolower(trim($this->attributes['email'])));
        return "https://secure.gravatar.com/avatar/".$hash;
    }

    public function isAdmin()
    {
        return $this->attributes['is_admin'];
    }

    public function lastSsTime()
    {
        if ($this->attributes['t'] == 0) {
            return "从未使用喵";
        }
        return Tools::toDateTime($this->attributes['t']);
    }

    public function getMuMd5()
    {
        $str = str_replace("%id", $this->attributes['id'], Config::get('mu_regex'));
        $str = str_replace("%suffix", Config::get('mu_suffix'), $str);
        preg_match_all("|%-?[1-9]\d*m|U", $str, $matches, PREG_PATTERN_ORDER);
        foreach ($matches[0] as $key) {
            $key_match = str_replace("%", "", $key);
            $key_match = str_replace("m", "", $key_match);
            $md5 = substr(MD5($this->attributes['id'].$this->attributes['passwd'].$this->attributes['method'].$this->attributes['obfs'].$this->attributes['protocol']),
            ($key_match < 0 ? $key_match : 0),
            abs($key_match));
            $str = str_replace($key, $md5, $str);
        }
        return $str;
    }

    public function lastCheckInTime()
    {
        if ($this->attributes['last_check_in_time'] == 0) {
            return "从未签到";
        }
        return Tools::toDateTime($this->attributes['last_check_in_time']);
    }

    public function regDate()
    {
        return $this->attributes['reg_date'];
    }

    public function updatePassword($pwd)
    {
        $this->pass = Hash::passwordHash($pwd);
        $this->save();
    }

    public function get_forbidden_ip()
    {
        return str_replace(",", PHP_EOL, $this->attributes['forbidden_ip']);
    }

    public function get_forbidden_port()
    {
        return str_replace(",", PHP_EOL, $this->attributes['forbidden_port']);
    }

    public function updateSsPwd($pwd)
    {
        $this->passwd = $pwd;
        $this->save();
    }

    public function updateMethod($method)
    {
        $this->method = $method;
        $this->save();
    }

    public function addInviteCode()
    {
        $uid = $this->attributes['id'];
        $code = new InviteCode();
        $code->code = Tools::genRandomChar(32);
        $code->user = $uid;
        $code->save();
    }

    public function addManyInviteCodes($num)
    {
        for ($i = 0; $i < $num; $i++) {
            $this->addInviteCode();
        }
    }

    public function trafficUsagePercent()
    {
        $total = $this->attributes['u'] + $this->attributes['d'];
        $transferEnable = $this->attributes['transfer_enable'];
        if ($transferEnable == 0) {
            return 0;
        }
        $percent = $total / $transferEnable;
        $percent = round($percent, 2);
        $percent = $percent * 100;
        return $percent;
    }

    public function enableTraffic()
    {
        $transfer_enable = $this->attributes['transfer_enable'];
        return Tools::flowAutoShow($transfer_enable);
    }
    public function enableTrafficInGB()
    {
        $transfer_enable = $this->attributes['transfer_enable'];
        return Tools::flowToGB($transfer_enable);
    }
    public function usedTraffic()
    {
        $total = $this->attributes['u'] + $this->attributes['d'];
        return Tools::flowAutoShow($total);
    }
    public function unusedTraffic()
    {
        $total = $this->attributes['u'] + $this->attributes['d'];
        $transfer_enable = $this->attributes['transfer_enable'];
        return Tools::flowAutoShow($transfer_enable - $total);
    }

    public function TodayusedTraffic()
    {
        $total = $this->attributes['u'] + $this->attributes['d']-$this->attributes['last_day_t'];
        return Tools::flowAutoShow($total);
    }

    public function LastusedTraffic()
    {
        $total = $this->attributes['last_day_t'];
        return Tools::flowAutoShow($total);
    }

    public function isAbleToCheckin()
    {
        $last = $this->attributes['last_check_in_time'];

        $now = time();
        if (date("Ymd", $now)!= date("Ymd", $last)) {
            return true;
        }
        return false;
    }

    /*
     * @param traffic 单位 MB
     */
    public function addTraffic($traffic)
    {
    }

    public function getGAurl()
    {
        $ga = new GA();
        $url = $ga->getUrl(urlencode(Config::get('appName')."-".$this->attributes['user_name']."-两步验证码"), $this->attributes['ga_token']);
        return $url;
    }

    public function inviteCodes()
    {
        $uid = $this->attributes['id'];
        return InviteCode::where('user_id', $uid)->get();
    }

    public function ref_by_user()
    {
        $uid = $this->attributes['ref_by'];
        return User::where('id', $uid)->first();
    }

    public function clean_link()
    {
        $uid = $this->attributes['id'];
        Link::where('userid', $uid)->delete();
    }

    public function online_ip_count()
    {
        $uid = $this->attributes['id'];
        $total = Ip::where("datetime", ">=", time()-90)->where('userid', $uid)->orderBy('userid', 'desc')->get();
        $unique_ip_list = array();
        foreach ($total as $single_record) {
            if (!in_array($single_record->ip, $unique_ip_list)) {
                array_push($unique_ip_list, $single_record->ip);
            }
        }

        return count($unique_ip_list);
    }

    public function kill_user()
    {
        $uid = $this->attributes['id'];
        $email = $this->attributes['email'];

        Radius::Delete($email);

        RadiusBan::where('userid', '=', $uid)->delete();
        Disconnect::where('userid', '=', $uid)->delete();
        Bought::where('userid', '=', $uid)->delete();
        Bought::where('userid', '=', $uid)->delete();
        Ip::where('userid', '=', $uid)->delete();
        Code::where('userid', '=', $uid)->delete();
        DetectLog::where('user_id', '=', $uid)->delete();
        Link::where('userid', '=', $uid)->delete();
        LoginIp::where('userid', '=', $uid)->delete();
        InviteCode::where('user_id', '=', $uid)->delete();
        TelegramSession::where('user_id', '=', $uid)->delete();
        UnblockIp::where('userid', '=', $uid)->delete();
        TrafficLog::where('user_id', '=', $uid)->delete();
        Token::where('user_id', '=', $uid)->delete();
        PasswordReset::where('email', '=', $email)->delete();

        Wecenter::Delete($email);

        $this->delete();

        return true;
    }
}
