<?php
namespace Yanzhi\Model;

class UserBaseModel extends CjDatadwModel
{

  protected $redis_config = 'redis_default';

  public $api_root;
  public $api_reg;
  public $api_loc;

  // 用户状态
  public $user_types = array(
    0 => '正常',
    1 => '打分团',
    2 => '封禁',
    3 => '运营账号',
    4 => '经纪人',
  );

  // 封禁时间
  public $closure_time = array();//C('STATE_CLOSURE_TIME')

  // 自动验证
  protected $_validate = array(
    array('nickname','require','昵称不能为空'),
    array('phone','/^1[34578]\d{9}$/i','手机号格式错误'),
    array('sex',array(0,1),'性别错误',0,'in'),
  );

  // 自动完成
  protected $_auto = array(
    array('attrs','auto_attrs',3,'callback'),
  );

  public function __construct()
  {
    parent::__construct();
    $this->closure_time = C('STATE_CLOSURE_TIME') ?: array();
    $this->api_root = C('app_api_root');
    $this->api_reg  = $this->api_root.'auth/reg';
    $this->api_loc  = $this->api_root.'lbs/update_location';
  }

  // 获取搜索筛选条件
  //   $alias 表别名，为true时自动获取
  public function get_filters($alias = '',$arr = array())
  {
    is_array($arr) && $arr || $arr = $_REQUEST ?: array();
    $alias === true && $alias = $this->options['alias'] ?: $this->getTableName();
    $alias = $alias ? ($alias.'.') : '';
    $map = array();
    if($arr['type'] != '')
    {
      $map[$alias.'type'] = (int)$arr['type'];
    }
    if($arr['stime'] && $stime = strtotime($arr['stime']))
    {
      is_array($map[$alias.'reg_time']) || $map[$alias.'reg_time'] = array();
      $map[$alias.'reg_time'][] = array('egt',strtotime(date('Y-m-d',$stime)));
    }
    if($arr['etime'] && $etime = strtotime($arr['etime']))
    {
      is_array($map[$alias.'reg_time']) || $map[$alias.'reg_time'] = array();
      $map[$alias.'reg_time'][] = array('elt',strtotime(date('Y-m-d 23:59:59',$etime)));
    }
    if($arr['sex'] != '')//性别筛选
    {
      $sex = (int)$arr['sex'] == 0 ? 0 : 1;
      $map[$alias.'sex'] = $sex;
    }
    if($prov = trim($arr['province']))//省份筛选
    {
      $loc = D('LocationBase');
      $whe = array(
        'id'       => array('exp',' = '.$loc->getTableName().'.city_id'),
        'province' => array('like',$prov.'%'),
      );
      $sql = D('CityBase')->field('id')->where($whe)->buildSql();
      $whe = array(
        'uid'     => array('exp',' = '.$alias.'uid'),
        '_string' => 'exists '.$sql,
      );
      $sql = $loc->field('uid')->where($whe)->buildSql();
      $map['_string'] .= ($map['_string'] ? ' and ' : '').'exists '.$sql;
    }
    if($kwd = trim($arr['kwd']))
    {
      $map['_complex'] = array(
        '_logic'   => 'or',
        $alias.'nickname' => array('like','%'.$kwd.'%'),
      );
      if(preg_match('/^\d+$/i',$kwd)) $map['_complex'][$alias.'phone'] = array('like','%'.$kwd.'%');
    }
    return $map;
  }

  // 格式化昵称 emoji 替换敏感词
  public function format_nickname_all($arr = array())
  {
    import('Think.Emoji');
    $wds = array();
    foreach(D('SensitiveWords')->get_multi_items('','word') ?: array() as $v)
    {
      $wds[$v['word']] = '<a style=color:red;font-weight:900;>'.$v['word'].'</a>';
    }
    $arr = array_map(function($v) use($wds)
    {
      $v['nickname_html'] = emoji_unified_to_html(strtr($v['nickname'],$wds ?: array()));
      return $v;
    },$arr ?: array());
    //print_r([$wds,$arr]);die;
    return $arr;
  }

  // 封禁用户
  public function closure($uid = 0,$status = 0)
  {
    $ret = array('ret' => 0,'msg' => '');
    $uid = (int)$uid;
    if($uid < 1)
    {
      $ret['ret'] = 1;
      $ret['msg'] = 'ID错误';
    }
    elseif(!array_key_exists($status,$this->closure_time))
    {
      $ret['ret'] = 1;
      $ret['msg'] = '封禁状态错误';
    }
    elseif(!$old = $this->find($uid))
    {
      $ret['ret'] = 1;
      $ret['msg'] = '用户不存在';
    }
    // 封禁解除 用户未封禁
    elseif($status == 1 && (int)$old['dblocking_time'] < time())
    {
      $ret['ret'] = 1;
      $ret['msg'] = '用户未封禁';
    }
    // 封禁用户 用户已封禁
    elseif($status != 1 && $old['type'] == 2 && (int)$old['dblocking_time'] > time())
    {
      $ret['ret'] = 1;
      $ret['msg'] = '用户已被封禁（解封时间：'.date('Y-m-d H:i',$old['dblocking_time']).'）';
    }
    else
    {
      $tim = strtotime($this->closure_time[$status]);
      $typ = $tim > time() ? 2 : 0;
      $dat = array('type' => $typ,'dblocking_time' => $tim);
      if(false === $this->where(array('uid' => $uid))->save($dat))
      {
        $ret['ret'] = 1;
        $ret['msg'] = '操作失败';
      }
      else
      {
        $ret['msg'] = '操作成功';
        $ret['user'] = array_merge($old,$dat);
        $this->del_user_token($uid);
      }
    }
    return $ret;
  }

  // 通过API更新用户位置
  public function set_user_location($uid = 0,$dat = array())
  {
    $loc = array_merge(array(
      'uid'   => (int)$uid,
      'sex'   => $dat['sex'],
      'token' => $dat['token'],
      'lat'   => '31.103680',
      'lng'   => '121.515080',
    ),$dat ?: array());
    $jss = A($this->name)->http($this->api_loc,$loc,'POST');
    $arr = json_decode($jss,true) ?: array();
    return $arr;
  }

  // 密码加密
  public function password_encrypt($pwd = '')
  {
    return md5($pwd.'4jfr84fjad');
  }

  // 获取用户缓存
  public function get_user_cache($uid = 0)
  {
    $this->redis || $this->redis = $this->new_redis();
    $ret = $this->redis->get('php_user_'.$uid);
    is_string($ret) && $ret = json_decode($ret,true);
    return $ret ?: array();
  }

  // 删除用户缓存
  public function del_user_cache($uid = 0)
  {
    $this->redis || $this->redis = $this->new_redis();
    return $this->redis->del('php_user_'.$uid);
  }

  // 获取用户Token
  public function get_user_token($uid = 0)
  {
    $this->redis || $this->redis = $this->new_redis();
    return $this->redis->hGet($uid,'auth_token');
  }

  // 删除用户Token
  public function del_user_token($uid = 0)
  {
    $this->redis || $this->redis = $this->new_redis();
    return $this->redis->hDel($uid,'auth_token');
  }

  public function new_redis($cfg = 'user_info_v6')
  {
    $this->redis || $this->redis = D('PhpServerRedis')->new_redis($cfg);
    return $this->redis;
  }

}