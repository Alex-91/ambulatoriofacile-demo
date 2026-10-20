<?php
namespace App\Services;
final class WhatsAppBookingAccess
{
    public static function platformEnabled(int $tenant): bool
    {
        if($tenant<=0)return false;
        try{return (new WhatsAppBookingService())->enabled($tenant);}
        catch(\Throwable $e){log_message('error','WhatsApp menu availability lookup failed.');return false;}
    }
    public static function canManage(?array $membership): bool
    {
        return $membership!==null && (int)($membership['tenant_is_active']??0)===1
            && ($membership['invitation_status']??'')==='accepted'
            && (in_array($membership['tenant_role']??'',['tenant_master','tenant_admin'],true)||(int)($membership['is_app_admin']??0)===1);
    }
}
