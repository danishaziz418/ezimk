<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Smstransaction;
use App\Models\Smstesttransactions;
use App\Http\Requests\Bulkrequest;
use App\Models\User;
use App\Models\App;
use App\Models\Device;
use App\Models\Contact;
use App\Models\Template;
use App\Models\Reply;
use App\Models\Webhook;
use Carbon\Carbon;
use App\Traits\Whatsapp;
use Http;
use Auth;
use Str;
use DB;
use Session;
use Throwable;
class BulkController extends Controller
{
    use Whatsapp;

    
    /**
     * sent message
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function submitRequest(Bulkrequest $request)
    {

       
        $user=User::where('status',1)->where('will_expire','>',now())->where('authkey',$request->authkey)->first();
        $app=App::where('key',$request->appkey)->whereHas('device')->with('device')->where('status',1)->first();

        if ($user == null || $app == null) {
            return response()->json(['error'=>'Invalid Auth and AppKey'],401);
        }

        if (getUserPlanData('messages_limit', $user->id) == false) {
            return response()->json([
                'message'=>__('Maximum Monthly Messages Limit Exceeded')
            ],401);  
        }

        if (!empty($request->template_id)) {

            $template = Template::where('user_id',$user->id)->where('uuid',$request->template_id)->where('status',1)->first();
            if (empty($template)) {
                return response()->json(['error'=>'Template Not Found'],401);
            }

            if (isset($template->body['text'])) {
                $body = $template->body;
                $text=$this->formatText($template->body['text'],[],$user);
                $text=$this->formatCustomText($text,$request->variables ?? []);
                $body['text'] = $text;
            }
            else{
                $body=$template->body;
            }
            $type = $template->type;

            
        }
        else{
            
            $text=$this->formatText($request->message);
            if(!empty($request->file)){
               
           
                    $explode=explode('.', $request->file);
                    $file_type=strtolower(end($explode));
                    $extentions=[
                        'jpg'=>'image',
                        'jpeg'=>'image',
                        'png'=>'image',
                        'webp'=>'image',
                        'pdf'=>'document',
                        'docx'=>'document',
                        'xlsx'=>'document',
                        'csv'=>'document',
                        'txt'=>'document'
                    ];
                   
                    if(!isset($extentions[$file_type])){
                        $validators['error'] = 'file type should be jpg,jpeg,png,webp,pdf,docx,xlsx,csv,txt';
                        return response()->json($validators,403);
                    }

                
                $body[$extentions[$file_type]]=['url' => $request->file];
                $body['caption'] = $text;
                $type='text-with-media';
            }
            else{
                $body['text'] = $text;
                $type='plain-text';
            }
            
        }

        if (!isset($body)) {
            return response()->json(['error'=>'Request Failed'],401);
        }    

        try {

            $response= $this->messageSend($body,$app->device_id,$request->to,$type,true);

            if ($response['status'] == 200) {
                
                $logs['user_id']=$user->id;
                $logs['device_id']=$app->device_id;
                $logs['app_id']=$app->id;
                $logs['from']=$app->device->phone ?? null;
                $logs['to']=$request->to;
                $logs['template_id']=$template->id ?? null;
                $logs['type']='from_api';
                $logs['whatsapp_message_id']=$response['whatsapp_message_id'] ?? null;

                $this->saveLog($logs);

                return response()->json(['message_status'=>'Success','data'=>[
                    'from'=>$app->device->phone ?? null,
                    'to'=>$request->to,                
                    'status_code'=>200,
                ]],200);
            }
            else{
                return response()->json(['error'=>'Request Failed'],401);

            }

        } catch (Exception $e) {

         return response()->json(['error'=>'Request Failed'],401);
     }

 }


 /**
  * set status device
  * @param  \Illuminate\Http\Request  $request
  * @return \Illuminate\Http\Response
  */
  public function setStatus($device_id,$status){

       $device_id=str_replace('device_','',$device_id);

       $device=Device::where('id',$device_id)->first();
       if (!empty($device)) {
          $device->status=$status;
          $device->save();
       }


  }


  /**
  * receive webhook response
  * @param  \Illuminate\Http\Request  $request
  * @return \Illuminate\Http\Response
  */
  public function webHook(Request $request,$device_id){
   

       $session=$device_id;
       $device_id=str_replace('device_','',$device_id);

       $device=Device::with('user')->whereHas('user',function($query){
        return $query->where('will_expire','>',now());
       })->where('id',$device_id)->first();

      if ($device == null) {
        return response()->json(['message' => 'Device not found or inactive'], 404);
      }

      if ($request->type == 'CONNECTION_UPDATE') {
        if (isset($request->data['connection'])) {

             if ($request->data['connection'] == 'close') {
                $device_status = 0;
                $device->status = $device_status;
                $device->save();
            } elseif ($request->data['connection'] == 'open') {
                $device_status = 1;
                $device->status = $device_status;
                $device->save();
            } 

            return true;
            
          
        }

        
       }


       if (isset($request->data[0]['key']['remoteJidAlt']) || isset($request->data[0]['key']['remoteJid'])) {
        $request_from=explode('@',$request->data[0]['key']['remoteJidAlt'] ?? $request->data[0]['key']['remoteJid']) ?? null;  
        $request_from = $request_from[0] ?? null;   
       }
       
       
      
       
     
      
       $device_id=$device_id;
       
       if (isset($request->data[0]['message'])) {
            $incomingMessage = $request->data[0];
            $incomingKey = $incomingMessage['key'] ?? [];
            $messageData = $incomingMessage['message'] ?? [];
            $message = $this->extractIncomingMessageText($messageData);
            $replyContext = $this->extractIncomingReplyContext($messageData);
            $incomingMessageId = $incomingKey['id'] ?? null;
            $conversationId = $incomingKey['remoteJidAlt'] ?? $incomingKey['remoteJid'] ?? null;
            $receivedAt = isset($incomingMessage['messageTimestamp'])
                ? Carbon::createFromTimestamp((int) $incomingMessage['messageTimestamp'])->toDateTimeString()
                : now()->toDateTimeString();

            if($device->hook_url && $message !== null){
            $payload = [
                'payload' => [
                    'type' => 'MESSAGE_RECEIVED',
                    'data' => [
                        'message_id' => $incomingMessageId,
                        'conversation_id' => $conversationId,
                        'from' => $request_from ?? '',
                        'to' => $device->phone ?? '',
                        'device_id' => $device->id,
                        'message' => $message ?? '',
                        'received_at' => $receivedAt,
                        'reply_to_message_id' => $replyContext['message_id'],
                        'reply_to_sender' => $replyContext['sender'],
                        'reply_to_message' => $replyContext['message'],
                    ],
                ],
                'sender' => $request_from ?? '',
                'receiver' => $device->phone ?? '',
            ];
            $hook = new Webhook;
            $hook->device_id = $device->id;
            $hook->user_id = $device->user_id;
            $hook->payload = json_encode($payload);
            $hook->hook = $device->hook_url;
            $hook->save();
            $this->dispatchWebhook($hook);

        }

       if ($device != null && $message != null) {

        

         $reply=Reply::where('device_id',$device_id)->with('template')->where('keyword', $message)->where('match_type','equal')->latest()->first();
           
          if (empty($reply)) {
              $messages = explode(' ',$message);
              if (count($messages) < 50) {
                 $reply=Reply::where('device_id',$device_id)->where('match_type','!=','equal')->with('template');

                 $reply = $reply->where(function($query) use ($messages){
                    for ($i = 0; $i < count($messages); $i++) {
                      $reply= $query->orWhere("keyword", 'like', '%' . $messages[$i] . '%');

                   }
                 });
                 

                $reply= $reply->latest()->first();
              }
             
          }
          
         
          
          if ($reply != null) {
               

                if ($reply->reply_type == 'text') {
                  
                  $logs['user_id']=$device->user_id;
                  $logs['device_id']=$device->id;
                  $logs['from']=$device->phone ?? null;
                  $logs['to']=$request_from;
                  $logs['type']='chatbot';
                  $logs['reply_to_message_id']=$incomingMessageId;
                  $logs['conversation_id']=$conversationId;
                $body= array('text' => $reply->reply);

             
                $response= $this->messageSend($body,$device->id,$request_from,'plain-text',true);
                $logs['whatsapp_message_id']=$response['whatsapp_message_id'] ?? null;
                $this->saveLog($logs);
                  
                 return response()->json([
                    'message'  => array('text' => $reply->reply),
                    'receiver' => $request->from,
                    'session_id' => $session
                  ],200);

                 
                }
                else{
                    if (!empty($reply->template)) {
                        $template = $reply->template;

                        if (isset($template->body['text'])) {
                            $body = $template->body;
                            $text=$this->formatText($template->body['text'],[],$device->user);
                            $body['text'] = $text;
                            
                        }
                        else{
                            $body=$template->body;
                        }

                        $logs['user_id']=$device->user_id;
                        $logs['device_id']=$device->id;
                        $logs['from']=$device->phone ?? null;
                        $logs['to']=$request_from;
                        $logs['type']='chatbot';
                        $logs['template_id']=$template->id ?? null;
                        $logs['reply_to_message_id']=$incomingMessageId;
                        $logs['conversation_id']=$conversationId;
                        $response= $this->messageSend($body,$device->id,$request_from,$template->type,true);
                        $logs['whatsapp_message_id']=$response['whatsapp_message_id'] ?? null;
                        $this->saveLog($logs);
                        return response()->json([
                            'message'  => $body,
                            'receiver' => $request->from,
                            'session_id' => $session
                        ],200);
                    }                    
                }
                             
            
          }
       }
           
       }
     
      
       return true;
       
    }

    private function dispatchWebhook(Webhook $hook): void
    {
        try {
            $response = Http::timeout(10)->post($hook->hook, json_decode($hook->payload, true) ?? []);
            $hook->status = $response->successful() ? 1 : 0;
            $hook->status_code = $response->status();
        } catch (Throwable $e) {
            $hook->status = 0;
            $hook->status_code = 500;
        }

        $hook->save();
    }

    private function extractIncomingMessageText(array $message): ?string
    {
        $text = $message['conversation']
            ?? $message['extendedTextMessage']['text']
            ?? $message['imageMessage']['caption']
            ?? $message['videoMessage']['caption']
            ?? $message['documentMessage']['caption']
            ?? null;

        if ($text === null && isset($message['ephemeralMessage']['message'])) {
            return $this->extractIncomingMessageText($message['ephemeralMessage']['message']);
        }

        if ($text === null && isset($message['viewOnceMessage']['message'])) {
            return $this->extractIncomingMessageText($message['viewOnceMessage']['message']);
        }

        if ($text === null && isset($message['viewOnceMessageV2']['message'])) {
            return $this->extractIncomingMessageText($message['viewOnceMessageV2']['message']);
        }

        return $text;
    }

    private function extractIncomingReplyContext(array $message): array
    {
        $contextInfo = $message['extendedTextMessage']['contextInfo']
            ?? $message['imageMessage']['contextInfo']
            ?? $message['videoMessage']['contextInfo']
            ?? $message['documentMessage']['contextInfo']
            ?? [];

        $quotedMessage = $contextInfo['quotedMessage'] ?? [];

        return [
            'message_id' => $contextInfo['stanzaId'] ?? null,
            'sender' => $contextInfo['participant'] ?? null,
            'message' => is_array($quotedMessage) ? $this->extractIncomingMessageText($quotedMessage) : null,
        ];
    }
}
