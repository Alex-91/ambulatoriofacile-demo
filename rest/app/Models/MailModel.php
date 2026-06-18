<?php

namespace App\Models;

use CodeIgniter\Model;
use App\Libraries\Crypto_helper; // Importa la libreria

class MailModel extends Model
{
  
    public function getMailsByUser($obj,$gestiti,$limit)
    {
        $crypto_helper = new Crypto_helper();
        $sql=" select * from (SELECT a.id_message,a.id_message as id_message_ini,".$crypto_helper->decrypt("a.testo")."
            ,".$crypto_helper->decrypt("a.oggetto").",
            CASE WHEN dataora<str_to_date(CONCAT(date_format(now(),'%d-%m-%Y'),' 00:00:00'),'%d-%m-%Y %H:%i:%s') then CONCAT(date_format(dataora,'%d'),' ',UPPER(SUBSTRING(date_format(dataora,'%b'),1,1)),LOWER(SUBSTRING(date_format(dataora,'%b'),2))) 
                else date_format(dataora,'%H:%i') end as data,dataora,id_mitt,id_dest,mitt,dest,
            CASE WHEN (a.letto=0 AND a.seg_flag=1 ) THEN 1 else a.letto end as letto,CASE WHEN gestita=0 THEN 'NON GESTITA' else 'GESTITA' end as gestita,CASE WHEN gestita=0 THEN 'fa-check' else 'fa-minus-square' end as icon,gestita as gestita_num
            FROM ".$obj->tabella." a,dap10_message_delete b where
            a.id_dest=:id_personale and b.id_utente=".$obj->id_personale." and a.draft=0 and b.id_message=a.id_message and b.eliminato=0 /*and seg_flag=0 and inf_flag=0*/ and a.dest='P' ".$gestiti."
            UNION ALL
            SELECT a.id_message,id_message_ini,AES_DECRYPT(UNHEX(a.testo),@key_str,a.vector_id) as testo
            ,AES_DECRYPT(UNHEX(a.oggetto),@key_str,a.vector_id) as oggetto,
            CASE WHEN a.dataora<str_to_date(CONCAT(date_format(now(),'%d-%m-%Y'),' 00:00:00'),'%d-%m-%Y %H:%i:%s') then CONCAT(date_format(a.dataora,'%d'),' ',UPPER(SUBSTRING(date_format(a.dataora,'%b'),1,1)),LOWER(SUBSTRING(date_format(a.dataora,'%b'),2))) 
                else date_format(a.dataora,'%H:%i') end as data,a.dataora,a.id_mitt,a.id_dest,a.mitt,a.dest,
            CASE WHEN (a.letto=0 AND a.seg_flag=1 ) THEN 1 else a.letto end as letto,CASE WHEN c.gestita=0 THEN 'NON GESTITA' else 'GESTITA' end as gestita,CASE WHEN c.gestita=0 THEN 'fa-check' else 'fa-minus-square' end as icon,c.gestita as gestita_num
            FROM ".$obj->tabella_reply." a, dap10_message_reply_delete b,dap10_message c where a.id_message_ini=c.id_message and
            a.id_dest=:id_personale and b.eliminato=0 and a.id_message=b.id_message and b.id_utente=".$obj->id_personale." and a.draft=0 and a.dest='P' ".$gestiti." 

            ) as a order by a.dataora desc,a.letto LIMIT ".$limit;
        log_message('ERROR', "query EMAIL".$sql);
        $query = $db->query($sql);
        $result = $query->getResultArray();
        if (!empty($result)) {
            foreach ($result as $row) {
              //$row['icon']
              if($obj->tipo==2)
              {
                  if($row['mitt']=='C')
                  {
                  $stmt2 =  $db->query("select CONCAT(AES_DECRYPT(UNHEX(b.cognome),@key_str,b.vector_id),' ',AES_DECRYPT(UNHEX(b.nome),@key_str,b.vector_id)) as mittente
                  from dap02_clients b where b.id_client=".$row['id_mitt']);
                  }
                  else if($row['mitt']=='S')
                  {
                  $stmt2 =  $db->query("select CONCAT('Dalla Segreteria per conto di: ',AES_DECRYPT(UNHEX(b.cognome),@key_str,b.vector_id),' ',AES_DECRYPT(UNHEX(b.nome),@key_str,b.vector_id)) as mittente
                  from dap02_clients b where b.id_client=".$row['id_mitt']);
                  }
                  else if($row['mitt']=='I')
                  {
                  $stmt2 =  $db->query("select CONCAT('Dall\'Infermiere per conto di: ',AES_DECRYPT(UNHEX(b.cognome),@key_str,b.vector_id),' ',AES_DECRYPT(UNHEX(b.nome),@key_str,b.vector_id)) as mittente
                  from dap02_clients b where b.id_client=".$row['id_mitt']);
                  }
                  else
                  {
                      $stmt2 =  $db->query("select CONCAT(IFNULL(".decrypt_concat('b.qualifica',$log).",''),' ',".decrypt_concat('b.cognome',$log).",' ',".decrypt_concat('b.nome',$log).") as mittente
                  from dap03_personale b where b.id_personale=".$row['id_mitt']);
                  }
                  $result2 = $stmt2->getResultArray();
                  if (!empty($result2)) {
                    foreach ($result2 as $row2) {
                        $mittente=$row2['mittente'];


                    }
                }
              }
              else
              {
                  $mittente=$row['mittente'];
              }
              $log->info($mittente);
              if (!in_array($row['id_message_ini'], $id_message_ini)) {

                        $class="";		
                        if($row['letto']==0){$class="notReading";$count_not_reading++;}
                        if($row['id_message_ini']!=null && $row['id_message_ini']!=$row['id_message']){$class.=" replace";}
                        $result_temp.="<tr id=\"".$row['id_message_ini']."\">
                        <td ><input  id=\"".$row['id_message_ini']."\" class=\"selMail\" type=\"checkbox\" name=\"email\"></td>
                        <td  id=\"".$row['id_message_ini']."\"  style=\"text-align: left;\" class=\"wrapText listMessage ".$class."\">".$mittente."</td>
                        <td  id=\"".$row['id_message_ini']."\" style=\"text-align: left;\" class=\"wrapText listMessage ".$class."\">".$row['oggetto']." - ".$row['testo']."</td>";
                    if($obj->tipo==2)	$result_temp.="<td style=\"font-size: 15px;\"><a href=\"#\" data-q_id=\"".$row['gestita_num']."\" id=\"".$row['id_message_ini']."\" class=\"gestisci\"><b>".$row['gestita']."</b><i class=\"fa ".$row['icon']."\" style=\"margin-left: 6px;\"></i></td>";
                        $result_temp.="<td style=\"font-size: 15px;\">".$row['data']."</td>
                        </tr>";
                            $id_message_ini[] = $row['id_message_ini'];
              }

        }
    }
}
