<?php
require_once __DIR__.'/workflows.php';
function faq_words(string $text):array {
 $text=mb_strtolower($text,'UTF-8');$words=preg_split('/[^\p{L}\p{N}]+/u',$text,-1,PREG_SPLIT_NO_EMPTY);
 return array_values(array_unique(array_diff($words,['the','a','an','is','are','what','how','do','i','to','of','for','please','can','you'])));
}
function faq_answer(int $pid,string $question):?string {
 global $conn;$scores=[];$questionWords=faq_words($question);
 if(!$questionWords)return null;
 foreach($conn->execute_query("SELECT question,answer FROM faqs WHERE (parish_id=? OR parish_id IS NULL) AND status='active'",[$pid]) as $faq){
  $words=faq_words($faq['question']);$union=array_unique(array_merge($words,$questionWords));$score=count(array_intersect($words,$questionWords))/max(1,count($union));
  $scores[]=['score'=>$score,'answer'=>$faq['answer']];
 }
 usort($scores,fn($a,$b)=>$b['score']<=>$a['score']);
 if(!$scores||$scores[0]['score']<0.72||($scores[0]['score']-($scores[1]['score']??0))<0.15)return null;
 return $scores[0]['answer'];
}
function help_question(array $actor,int $pid,string $body):void {
 global $conn;must(strlen(trim($body))>=2&&strlen($body)<=2000,'Enter a question of 2–2000 characters.');
 $parish=sqlrow("SELECT id FROM parishes WHERE id=? AND status='active' FOR UPDATE",[$pid]);must($parish!==null,'Choose an active parish.');
 $thread=sqlrow('SELECT * FROM help_conversations WHERE user_id=? AND parish_id=? FOR UPDATE',[$actor['id'],$pid]);
 if(!$thread){$staff=sqlrow("SELECT id FROM users WHERE parish_id=? AND role='secretary' AND status='active' ORDER BY id LIMIT 1",[$pid]);must($staff!==null,'No secretary is currently assigned. Please contact the parish office.');
  $conn->execute_query('INSERT INTO help_conversations(user_id,parish_id,secretary_id)VALUES(?,?,?)',[$actor['id'],$pid,$staff['id']]);$thread=['id'=>$conn->insert_id,'secretary_id'=>$staff['id'],'staff_active'=>0];
 }
 $conn->execute_query('INSERT INTO messages(sender_id,receiver_id,parish_id,body)VALUES(?,?,?,?)',[$actor['id'],$thread['secretary_id'],$pid,trim($body)]);
 $answer=$thread['staff_active']?null:faq_answer($pid,$body);
 if($answer!==null){$conn->execute_query('INSERT INTO messages(sender_id,receiver_id,parish_id,body,is_bot)VALUES(?,?,?,?,1)',[$thread['secretary_id'],$actor['id'],$pid,$answer]);}
 else{$conn->execute_query('UPDATE help_conversations SET staff_active=1 WHERE id=?',[$thread['id']]);notify($thread['secretary_id'],'Parish inquiry','A parishioner needs your reply.','message','messages.php');}
}
