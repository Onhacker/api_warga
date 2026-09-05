<?php
// Included by workflow_integration.php inside its disposable database scope.
if (!isset($sync, $community, $installation, $db)) exit(1);
foreach (array('sekdes','kepala-desa') as $role) $db->insert('roles',array('name'=>$role,'slug'=>$role));
$staffSnapshot=array('source_revision'=>'100','verification'=>array('sekdes'=>true,'kades'=>true),
    'contact'=>array('institution'=>'Kampung','phone'=>'081234567890'), 'staff'=>array());
foreach (array('sekdes','kepala-desa') as $role) $staffSnapshot['staff'][]=array('id'=>api_uuid(),
    'name'=>'Test '.$role,'email'=>$role.'@example.test','role'=>$role,
    'password_hash'=>password_hash('test-password-only',PASSWORD_BCRYPT),'is_active'=>1);
check(push_message($sync,$installation,'staff_accounts','snapshot',$staffSnapshot)['accepted']===1,'local staff snapshot creates two PWA accounts');
$staffUsers=array();
foreach ($staffSnapshot['staff'] as $person) {
    $staffUsers[$person['role']]=$db->where('email',$person['email'])->get('users')->row_array();
    $staffUsers[$person['role']]['role_slug']=$person['role'];
    check(password_verify('test-password-only',$staffUsers[$person['role']]['password_hash']),'synced password verifies for '.$person['role']);
}
$sekdes=$staffUsers['sekdes']; $kades=$staffUsers['kepala-desa'];
$audit=$db->where('aggregate_type','staff_accounts')->get('sync_messages')->row_array();
check(strpos($audit['payload_json'],'password_hash')===false && strpos($audit['payload_json'],'example.test')===false,'sync audit does not retain staff credentials or contacts');
$pwaHash=password_hash('changed-on-phone',PASSWORD_BCRYPT);
$db->where('id',$sekdes['id'])->update('users',array('password_hash'=>$pwaHash));
$staffSnapshot['source_revision']='101'; $staffSnapshot['verification']['kades']=false;
check(push_message($sync,$installation,'staff_accounts','snapshot',$staffSnapshot)['accepted']===1,'workflow settings update is accepted');
check($db->where('id',$sekdes['id'])->get('users')->row_array()['password_hash']===$pwaHash,'contact/workflow sync preserves password changed on phone');
$older=$staffSnapshot; $older['source_revision']='99'; $older['verification']['kades']=true;
$older['staff'][0]['name']='Outdated name';
check(push_message($sync,$installation,'staff_accounts','snapshot',$older)['accepted']===1
    && !$community->workflow($installation['village_id'])['kades'],'late snapshot cannot revert latest workflow');
check($db->where('id',$sekdes['id'])->get('users')->row_array()['name']===$sekdes['name'],'late snapshot cannot revert staff profile');
$staffSnapshot['source_revision']='102'; $staffSnapshot['staff'][0]['password_hash']=password_hash('local-reset-password',PASSWORD_BCRYPT);
check(push_message($sync,$installation,'staff_accounts','snapshot',$staffSnapshot)['accepted']===1
    && password_verify('local-reset-password',$db->where('id',$sekdes['id'])->get('users')->row_array()['password_hash']),'explicit local password reset is applied');
$broken=$staffSnapshot; $broken['source_revision']='103'; unset($broken['staff']);
check(push_message($sync,$installation,'staff_accounts','snapshot',$broken)['rejected']===1,'missing staff list cannot deactivate all accounts');
$broken=$staffSnapshot; $broken['source_revision']='103'; $broken['staff'][1]['email']=$broken['staff'][0]['email'];
check(push_message($sync,$installation,'staff_accounts','snapshot',$broken)['rejected']===1,'duplicate staff email is rejected transactionally');
$stolen=$staffSnapshot; $stolen['source_revision']='103';
check(push_message($sync,$other,'staff_accounts','snapshot',$stolen)['rejected']===1,'another village cannot take over existing staff emails');
$removed=$staffSnapshot; $removed['source_revision']='103'; $removed['staff']=array();
check(push_message($sync,$installation,'staff_accounts','snapshot',$removed)['accepted']===1
    && (int)$db->where('id',$sekdes['id'])->get('users')->row_array()['is_active']===0,'explicit empty snapshot disables staff');
$staffSnapshot['source_revision']='104'; $staffSnapshot['verification']=array('sekdes'=>true,'kades'=>true);
check(push_message($sync,$installation,'staff_accounts','snapshot',$staffSnapshot)['accepted']===1
    && (int)$db->where('email',$sekdes['email'])->get('users')->row_array()['id']===(int)$sekdes['id'],'restored account reuses original user rather than duplicating it');
check($community->village($installation['village_id'])['institution']==='Kampung','village identity is available for contact screen');

$citizen['role_slug']='warga';
$foreignStaff=$kades; $foreignStaff['village_id']=$other['village_id'];
$announcement=$community->publish($sekdes,'Pengumuman Uji','Isi pengumuman pengujian layanan warga.');
check((bool)$announcement,'Sekdes can publish an announcement');
check((bool)$community->announcement($announcement,$citizen),'resident sees own village announcement');
check(!$community->announcement($announcement,$foreignStaff),'other village cannot read announcement');
check(!$community->publish($citizen,'Judul','Isi'),'resident cannot publish village announcements');
check($community->archive_announcement($announcement,$kades) && !$community->announcement($announcement,$citizen),'archived announcement is hidden from residents');
$complaint=$community->submit_complaint($citizen,'Pengaduan Uji','Isi pengaduan pengujian layanan warga.','Lokasi uji');
check((bool)$complaint,'verified resident submits a complaint');
$complaintNotices=$db->where('target_path','pengaduan/'.$complaint)->get('warga_notification_targets')->num_rows();
check($complaintNotices===2,'complaint notifies both Sekdes and Kades');
check(!$community->complaint($complaint,$foreignStaff),'other village cannot read complaint');
$otherCitizen=$citizen; $otherCitizen['id']=$kades['id'];
check(!$community->complaint($complaint,$otherCitizen),'another resident cannot read private complaint');
check(!$community->reply($complaint,$citizen,'Balasan uji','resolved'),'resident cannot act as complaint officer');
check($community->reply($complaint,$kades,'Laporan sedang ditindaklanjuti.','processing'),'Kades can respond to own village complaint');
check(count($community->replies($complaint))===1 && $community->complaint($complaint,$citizen)['status']==='processing','resident sees reply and updated complaint status');
$list=$notifications->listing($citizen);
check($list['total']>=2 && $notifications->unread($citizen['id'])>=2,'resident sees announcement and complaint notifications');
check($notifications->target(array('target_path'=>'https://evil.test'),$citizen)==='notifikasi','notification cannot redirect outside PWA');
check(!$notifications->valid_endpoint('https://127.0.0.1/push') && !$notifications->valid_endpoint('https://fcm.googleapis.com.evil.test/push'),'push destination rejects local and spoofed hosts');
$notifications->read($citizen['id']);
check($notifications->unread($citizen['id'])===0 && $notifications->unread($sekdes['id'])>0,'mark read only affects current user');
