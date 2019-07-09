<?php namespace Boroda\BastApp\Components;

use Lang;
use Auth;
use Event;
use Flash;
use Input;
use Request;
use Redirect;
use Validator;
use ValidationException;
use ApplicationException;
use Cms\Classes\Page;
use Cms\Classes\ComponentBase;
use Exception;
use Boroda\BastApp\Models\TechTypeParams;
use Boroda\Bastapp\Models\Tech;
use Boroda\Bastapp\Models\TechParams;
use Illuminate\Support\Facades\Session;
use Boroda\Bastapp\Models\Shedule;
use Boroda\Bastapp\Models\Documents;
use Renatio\DynamicPDF\Classes\PDF;
use System\Models\File as File;
use Boroda\BastApp\Models\Counters;
use Boroda\Bastapp\Models\Notifications as Notify;
use Rainlab\User\Models\User;
use Boroda\Bastapp\Models\PayHistory as PayModel;
use Cms\Models\ThemeData;

class TechListPrivate extends ComponentBase
{

    public $techs;
    public function componentDetails()
    {
        return [
            'name'        => 'Вывод техники на приватных страницах',
            'description' => 'Заявки, Бронь, и т.д.'
        ];
    }

    public function defineProperties()
    {
        return [
            'slug' => [
                'title'       => '',
                'description' => '',
                'default'     => '',
                'type'        => 'string'
            ],
        ];
    }

    protected function redirectForceSecure()
    {
        if (
            Request::secure() ||
            Request::ajax() ||
            !$this->property('forceSecure')
        ) {
            return;
        }

        return Redirect::secure(Request::path());
    }


    public function prepareVars(){


    }


    public function onRun()
    {

        $this->reqs = $this->page['reqs'] = $this->loadRequestList();
        $this->reserves = $this->page['reserves'] = $this->loadReserveList();
        $this->mytech = $this->page['mytech'] = $this->loadMyTechList();
        $this->prepareVars();
    }

    public function onDeleteShedule()
    {
        try {
            $id = post('id');
            $user = Auth::getUser();

            $roleId = $user->role_id;
            if ($roleId==4){
                $userId = $user->groups->first()->parent_id;
                $p = explode(",", $user->groups->first()->permissions);
                if (!in_array("cancelrequests", $p)) {
                    return false;
                }
            }
            else {
                $userId = $user->id;
            }


            $shedule = Shedule::where([
                    ['lessor_id', '=', $userId],
                    ['id', '=', $id],])
                ->orWhere([
                    ['leaseholder_id', '=', $userId],
                    ['id', '=', $id],
                ])->first();

            if (!isset($shedule->id)){
                return false;
            }

            // Добавление уведомления.
            if ($user->user_type=='arendodatel'){
                $noty = new Notify;
                $noty->user_id = $shedule->leaseholder_id;
                $noty->name = "Подрядчик отказался";
                $noty->description = "Подрядчик отказался от выполнения заявки № ".$shedule->id." , по адресу ".$shedule->adress.".";
                $noty->save();
                $this->addCounter($shedule->leaseholder_id, 'notifications');

                $noty2 = new Notify;
                $noty2->user_id = $shedule->lessor_id;
                $noty2->name = "Вы отказались";
                $noty2->description = "Вы отказались от заявки № ".$shedule->id." , по адресу ".$shedule->adress.".";
                $noty2->save();
                $this->addCounter($shedule->lessor_id, 'notifications');
            }

            if ($user->user_type=='arendator'){
                $noty = new Notify;
                $noty->user_id = $shedule->leaseholder_id;
                $noty->name = "Вы отказались";
                $noty->description = "Вы отказались от заявки № ".$shedule->id." , по адресу ".$shedule->adress.".";
                $noty->save();
                $this->addCounter($shedule->leaseholder_id, 'notifications');

                $noty2 = new Notify;
                $noty2->user_id = $shedule->lessor_id;
                $noty2->name = "Зазазчик отказался";
                $noty2->description = "Заказчик отказался от заявки № ".$shedule->id." , по адресу ".$shedule->adress.".";
                $noty2->save();
                $this->addCounter($shedule->lessor_id, 'notifications');
            }


            $shedule->status = "deleted";
            $shedule->save();
            return Redirect::to('/requests/');

        }

        catch (Exception $ex) {
            if (Request::ajax()) throw $ex;
            else Flash::error($ex->getMessage());
        }
    }

    // Удаление техники
    public function onDeleteTech()
    {
        try {
            $id = post('id');
            $userId = Auth::getUser()->id;
            Tech::where([
                    ['tech_user_id', '=', $userId],
                    ['id', '=', $id],
                ])
                ->delete();
            return Redirect::to('/my-tech/');

        }

        catch (Exception $ex) {
            if (Request::ajax()) throw $ex;
            else Flash::error($ex->getMessage());
        }
    }

    // Выставление цены доставки арендодателем
    public function onChangeDeliveryPrice()
    {
        try {

            $id = post('id');
            $user = Auth::getUser();
            $roleId = $user->role_id;
            if ($roleId==4){
                $userId = $user->groups->first()->parent_id;
                $balance = $user->groups->first()->parent->balance;
                $freeDeals = $user->groups->first()->parent->free_deals;
            }
            else {
                $userId = $user->id;
                $balance = $user->balance;
                $freeDeals = $user->free_deals;
            }

            $shedule = Shedule::where([
                ['id', '=', $id],
                ['lessor_id', '=', $userId],
            ])->first();

            if (($balance < $shedule->price)&&($freeDeals==0)){
                return false;
            }
            $shedule->price_delivery = post('price');
            $shedule->status = 'new2';
            $shedule->save();

            // Отправка в раздел "Уведомления"

            $noty = new Notify;
            $noty->user_id = $shedule->leaseholder_id;
            $noty->name = "Подрядчик согласился";
            $noty->description = "Подрядчик дал предварительное согласие на выполнение заявки № ".$shedule->id." , по адресу ".$shedule->adress.", Примите или отклоните его предложение.";
            $noty->link = "/requests/?target=modal".$shedule->id;
            $noty->save();

            $this->addCounter($shedule->leaseholder_id, 'requests');
            $this->addCounter($shedule->leaseholder_id, 'notifications');


            return Redirect::to('/requests/');
        }

        catch (Exception $ex) {
            if (Request::ajax()) throw $ex;
            else Flash::error($ex->getMessage());
        }
    }



    public static function createPdfDocument($sheduleId, $type){
        $shedule = Shedule::where([
            ['id', '=', $sheduleId],
        ])->first();

        $doc = new Documents;
        $doc->id = $sheduleId;
        $passportNumber = 'passport-number';
        $passportDate = 'passport-date';
        $passportWho = 'passport-who';
        $passportCode = 'passport-code';
        $passportAddress = 'passport-address';

        $orgType = 'org-type';
        $orgName = 'org-name';
        $nameBoss = 'name-boss';
        $typeBoss = 'type-boss';
        $orgBase= 'org-base';
        $data = [
            'lessor_name' => $shedule->lessor->name,
            'lessor_passport_number' => $shedule->lessor->$passportNumber,
            'lessor_passport_date' => $shedule->lessor->$passportDate,
            'lessor_passport_who' => $shedule->lessor->$passportWho,
            'lessor_passport_code' => $shedule->lessor->$passportCode,
            'lessor_passport_address' => $shedule->lessor->$passportAddress,
            'lessor_org_type' => $shedule->lessor->$orgType,
            'lessor_org_name' => $shedule->lessor->$orgName,
            'lessor_name_boss' => $shedule->lessor->$nameBoss,
            'lessor_type_boss' => $shedule->lessor->$typeBoss,
            'lessor_org_base' => $shedule->lessor->$orgBase,
            'lessor_inn' => $shedule->lessor->inn,
            'lessor_kpp' => $shedule->lessor->kpp,
            'lessor_ks' => $shedule->lessor->ks,
            'lessor_rs' => $shedule->lessor->rs,
            'lessor_bank' => $shedule->lessor->bank,
            'lessor_bik' => $shedule->lessor->bik,
            'lessor_user_type_face' => $shedule->lessor->user_type_face,
            'leaseholder_user_type_face' => $shedule->leaseholder->user_type_face,
            'leaseholder_name' => $shedule->leaseholder->name,
            'leaseholder_passport_number' => $shedule->leaseholder->$passportNumber,
            'leaseholder_passport_date' => $shedule->leaseholder->$passportDate,
            'leaseholder_passport_who' => $shedule->leaseholder->$passportWho,
            'leaseholder_passport_code' => $shedule->leaseholder->$passportCode,
            'leaseholder_passport_address' => $shedule->leaseholder->$passportAddress,
            'leaseholder_org_type' => $shedule->leaseholder->$orgType,
            'leaseholder_org_name' => $shedule->leaseholder->$orgName,
            'leaseholder_name_boss' => $shedule->leaseholder->$nameBoss,
            'leaseholder_type_boss' => $shedule->leaseholder->$typeBoss,
            'leaseholder_org_base' => $shedule->leaseholder->$orgBase,
            'leaseholder_inn' => $shedule->leaseholder->inn,
            'leaseholder_kpp' => $shedule->leaseholder->kpp,
            'leaseholder_ks' => $shedule->leaseholder->ks,
            'leaseholder_rs' => $shedule->leaseholder->rs,
            'leaseholder_bank' => $shedule->leaseholder->bank,
            'leaseholder_bik' => $shedule->leaseholder->bik,
            'shedule' => $shedule,
            'tech' => $shedule->tech,
            'bill_date' => TechListPrivate::date4bill('j F Y'),
            'bill_sum' => number_format($shedule->price+$shedule->price_delivery, 2, ',', ' '),
            'bill_nds' => number_format(((($shedule->price+$shedule->price_delivery)/100)*20), 2, ',', ' '),
            'bill_sum_rus' => TechListPrivate::number2string($shedule->price+$shedule->price_delivery),
            'tech_type_name' => $shedule->tech->tech_type->tech_type_name
        ];

        if ($type=='dogovor'){

            // Шаблон договора

                $dogovor = PDF::loadTemplate('dogovor2', $data)
                    ->setOptions(['dpi' => 150, 'defaultFont' => 'sans-serif'])
                    ->output();


            $file = new File;
            $file->fromData($dogovor, 'dogovor'.$sheduleId.'.pdf');
            $doc->dogovor_template = $file;
            $doc->save();
            $file->attachment_id = $sheduleId;
            $file->save();
            return true;
        }
        if ($type=='bill'){
            $doc = Documents::where([
                ['id', '=', $sheduleId],
            ])->first();
            $data['dogovor_date'] = $doc->created_at;
            $bill = PDF::loadTemplate('bill', $data)
                ->setOptions(['dpi' => 150, 'defaultFont' => 'sans-serif'])
                ->output();
            $file = new File;
            $file->fromData($bill, 'bill'.$sheduleId.'.pdf');
            $doc->bill_template = $file;
            $doc->save();
            $file->attachment_id = $sheduleId;
            $file->save();
            return true;
        }
        if ($type=='act'){
            $doc = Documents::where([
                ['id', '=', $sheduleId],
            ])->first();
            $act = PDF::loadTemplate('act', $data)
                ->setOptions(['dpi' => 150, 'defaultFont' => 'sans-serif'])
                ->output();
            $file = new File;
            $file->fromData($act, 'act'.$sheduleId.'.pdf');
            $doc->act_template = $file;
            $doc->save();
            $file->attachment_id = $sheduleId;
            $file->save();
            return true;
        }

    }
    // Подтверждение Арендатором стоимости доставки.
    public function onPriceConfirm()
    {
        try {

            $id = post('id');
            $user = Auth::getUser();
            $roleId = $user->role_id;
            if ($roleId==4){
                $userId = $user->groups->first()->parent_id;
            }
            else {
                $userId = $user->id;
            }

            $shedule = Shedule::where([
                ['leaseholder_id', '=', $userId],
                ['id', '=', $id],
            ])->first();

            $shedule->status = 'reserve';
            $shedule->save();

            // Снимаем комиссию или бесплатную сделку
            $lessorId = $shedule->lessor_id;
            $lessor = User::where([
                ['id', '=', $lessorId]
            ])->first();

            $themeData = ThemeData::select('data')
                ->where([
                    ['id', '=', 3],
                ])->first();

            // Проверка есть ли бесплатные сделки.
            if ($lessor->free_deals > 0){

                // Снимаем бесплатную сделку
                $lessor->free_deals = $lessor->free_deals - 1;
                $lessor->save();
            }
            else {
                // Снимаем комиссию с баланса
                $fee = (($shedule->price)/100)*$themeData->fee;
                $lessor->balance = $lessor->balance - $fee;
                $lessor->save();

                // Пишем в историю платежей
                $payhistory = new PayModel;
                $payhistory->user_id = $shedule->lessor_id;
                $payhistory->type = "Комиссия";
                $payhistory->amount = $fee;
                $payhistory->shedule_id = $shedule->id;
                $payhistory->save();
                $this->addCounter($shedule->lessor_id, 'payhistory');

                $noty4 = new Notify;
                $noty4->user_id = $shedule->lessor_id;
                $noty4->name = " Комиссия оплачена";
                $noty4->description = "Заказчик принял ваши условия, заявка № ".$shedule->id." по адресу ".$shedule->adress." переведена в бронирование, мы сняли комиссию ".$themeData->fee."% (".$fee.")";
                $noty4->link = "/reserves/?target=modal".$shedule->id;
                $noty4->save();
                $this->addCounter($shedule->lessor_id, 'notifications');
            }
            // Создаем шаблон договора
            $this->createPdfDocument($id, 'dogovor');

            // Отправка в раздел "Уведомления"
            $noty = new Notify;
            $noty->user_id = $shedule->lessor_id;
            $noty->name = "Заявка переведена в статус бронирования";
            $noty->description = "Заказчик согласился с ценой доставки заявки № ".$shedule->id.", по адресу ".$shedule->adress.". Контактные данные открыты.";
            $noty->link = "/reserves/?target=modal".$shedule->id;
            $noty->link2 = "/october/documents/#".$shedule->id;
            $noty->link2text = "Просмотреть договор";
            $noty->save();

            $noty2 = new Notify;
            $noty2->user_id = $shedule->lessor_id;
            $noty2->name = "Новый договор по бронированию";
            $noty2->description = "Мы прислали вам договор по заявке № ".$shedule->id.". Пожалуйста загрузите подписанный договор на сайт";
            $noty2->link = "/documents/#".$shedule->id;
            $noty2->save();
            $this->addCounter($shedule->lessor_id, 'notifications');

            $noty3 = new Notify;
            $noty3->user_id = $shedule->leaseholder_id;
            $noty3->name = "Новый договор по бронированию";
            $noty3->description = "Мы прислали вам договор по заявке № ".$shedule->id.". Пожалуйста загрузите подписанный договор на сайт";
            $noty3->link = "/documents/#".$shedule->id;
            $noty3->save();
            $this->addCounter($shedule->leaseholder_id, 'notifications');
            $this->addCounter($shedule->lessor_id, 'reserves');
            $this->addCounter($shedule->lessor_id, 'notifications');
            $this->addCounter($shedule->leaseholder_id, 'documents');
            $this->addCounter($shedule->lessor_id, 'documents');
            return Redirect::to('/reserves/');
        }

        catch (Exception $ex) {
            if (Request::ajax()) throw $ex;
            else Flash::error($ex->getMessage());
        }
    }


    public function onConfirmEndShedule()
    {
        try {

            $id = post('id');
            $user = Auth::getUser();
            $roleId = $user->role_id;
            if ($roleId==4){
                $userId = $user->groups->first()->parent_id;
            }
            else {
                $userId = $user->id;
            }

            $shedule = Shedule::where([
                ['leaseholder_id', '=', $userId],
                ['id', '=', $id],
            ])->first();

            $shedule->status = "complete";
            $shedule->save();

            $noty = new Notify;
            $noty->user_id = $shedule->lessor_id;
            $noty->name = "Работа окончена";
            $noty->description = "Поздравляем! Работа по заявке № ".$shedule->id." окончена, пожалуйста подпишите акт в разделе “Мои документы”
.";
            $noty->link = "/documents/#".$shedule->id;
            $noty->linktext = "Мои документы";
            $noty->save();
            $this->addCounter($shedule->lessor_id, 'notifications');

            $this->createPdfDocument($id, 'act');
            $this->addCounter($shedule->lessor_id, 'documents');


            return Redirect::to('/ordershistory/');

        }

        catch (Exception $ex) {
            if (Request::ajax()) throw $ex;
            else Flash::error($ex->getMessage());
        }
    }


    public function onCommentAdd()
    {
        try {

            $id = post('id');
            $comment = post('comment');
            $score = post('score');
            $user = Auth::getUser();
            $roleId = $user->role_id;
            if ($roleId==4){
                $userId = $user->groups->first()->parent_id;
                $p = explode(",", $user->groups->first()->permissions);
                if (!in_array("leavecomments", $p)) {
                    return false;
                }
            }
            else {
                $userId = $user->id;
            }

            Shedule::where([
                ['leaseholder_id', '=', $userId],
                ['id', '=', $id],
            ])->update(['comment' => $comment, 'score' => $score] );
            return Redirect::to('/ordershistory/');

        }

        catch (Exception $ex) {
            if (Request::ajax()) throw $ex;
            else Flash::error($ex->getMessage());
        }
    }


    public function onCommentDelete()
    {
        try {

            $id = post('id');
            $user = Auth::getUser();
            $roleId = $user->role_id;
            if ($roleId==4){
                $userId = $user->groups->first()->parent_id;
                $p = explode(",", $user->groups->first()->permissions);
                if (!in_array("leavecomments", $p)) {
                    return false;
                }
            }
            else {
                $userId = $user->id;
            }

            Shedule::where([
                ['leaseholder_id', '=', $userId],
                ['id', '=', $id],
            ])->update(['comment' => ''] );
            return Redirect::to('/ordershistory/');

        }

        catch (Exception $ex) {
            if (Request::ajax()) throw $ex;
            else Flash::error($ex->getMessage());
        }
    }

	// Загрузка заявок
    protected function loadRequestList()
    {

        if (Auth::getUser()){
            $user = Auth::getUser();
            $roleId = $user->role_id;
            if ($roleId==4){
                $userId = $user->groups->first()->parent_id;
            }
            else {
                $userId = $user->id;
            }
            $reqs = Shedule::join('boroda_bastapp_tech', 'boroda_bastapp_shedule.tech_id', '=', 'boroda_bastapp_tech.id')
                ->where([
                    ['boroda_bastapp_tech.tech_user_id', '=', $userId],
                ])
                ->orWhere([
                    ['leaseholder_id', '=', $userId],
                ])
                ->select('boroda_bastapp_shedule.*', 'boroda_bastapp_tech.tech_user_id AS uid')
                ->orderBy('boroda_bastapp_shedule.id', 'desc')
                ->limit(100)
                ->get();

            return $reqs;
        }
    }

    protected function loadReserveList()
    {

        if (Auth::getUser()){
            $user = Auth::getUser();
            $roleId = $user->role_id;
            if ($roleId==4){
                $userId = $user->groups->first()->parent_id;
            }
            else {
                $userId = $user->id;
            }
            if (Auth::getUser()->user_type == 'arendator'){
                $reqs = Shedule::select('boroda_bastapp_shedule.*', 'users.*', 'boroda_bastapp_shedule.id as shid')->join('boroda_bastapp_tech', 'boroda_bastapp_shedule.tech_id', '=', 'boroda_bastapp_tech.id')
                    ->join('users', 'users.id', '=', 'boroda_bastapp_tech.tech_user_id')
                    ->where([
                        ['leaseholder_id', '=', $userId],
                    ])

                    ->orderBy('boroda_bastapp_shedule.id', 'desc')
                    ->limit(100)
                    ->get();
            }


            if (Auth::getUser()->user_type == 'arendodatel'){
                $reqs = Shedule::select('boroda_bastapp_shedule.*', 'users.*', 'boroda_bastapp_shedule.id as shid')->join('boroda_bastapp_tech', 'boroda_bastapp_shedule.tech_id', '=', 'boroda_bastapp_tech.id')
                    ->join('users', 'users.id', '=', 'boroda_bastapp_shedule.leaseholder_id')
                    ->where([
                        ['tech_user_id', '=', $userId],
                    ])

                    ->orderBy('boroda_bastapp_shedule.id', 'desc')
                    ->limit(100)
                    ->get();
            }

            return $reqs;
        }
    }

    public static function beforework24 () {


        $tomorrow  = mktime(0, 0, 0,date("m"), date("d")+1, date("Y"));
        $reqs = Shedule::where([
            ['status', '=', 'reserve'],
            ['date_start', '=', date('d.m.Y' ,$tomorrow)],
        ])
            ->get();
        $reqs->each(function($req) {
            $start = str_replace(':00', '', $req->time_start);
            if ($start==date("H")){
                // Уведомляем арендатора
                $noty = new Notify;
                $noty->user_id = $req->leaseholder_id;
                $noty->name = "";
                $noty->description = "Осталось 24 часа до выполнения заявки № ".$req->id.".";
                $noty->link = "/reserves/?target=del".$req->id;
                $noty->linktext = "Отменить заявку";
                $noty->save();
                TechListPrivate::addCounter($req->leaseholder_id, 'notifications');

                // Уведомляем арендодателя
                $noty2 = new Notify;
                $noty2->user_id = $req->lessor_id;
                $noty2->name = "";
                $noty2->description = "Осталось 24 часа до выполнения заявки № ".$req->id.".";
                $noty2->link = "/reserves/?target=del".$req->id;
                $noty2->linktext = "Отменить заявку";
                $noty2->save();
                TechListPrivate::addCounter($req->lessor_id, 'notifications');
            }

        });
    }

    // Автоотмена заявок старше 30 минут.
    public static function autocancelshedules () {

        $shedules = Shedule::where([
            ['status', '=', 'new']
        ])
            ->get();
        $shedules->each(function($shedule) {
            $deadline = strtotime($shedule->created_at)+1800;
            if (time()>$deadline){
                $shedule->status = 'deleted';
                $shedule->save();
                // Уведомляем арендатора
                $noty = new Notify;
                $noty->user_id = $shedule->leaseholder_id;
                $noty->name = "Автоотмена заявки";
                $noty->description = "Время ожидания ответа по заявке № ".$shedule->id.", адрес ".$shedule->adress."  от подрядчика истекло.";
                $noty->save();
                TechListPrivate::addCounter($shedule->leaseholder_id, 'notifications');

                $noty2 = new Notify;
                $noty2->user_id = $shedule->lessor_id;
                $noty2->name = "Автоотмена заявки";
                $noty2->description = "Отведенные 30 минут на принятие заявки № ".$shedule->id.", по адресу ".$shedule->adress." истекли.";
                $noty2->save();
                TechListPrivate::addCounter($shedule->lessor_id, 'notifications');
            }
        });
    }

    protected function loadMyTechList()
    {

        if (Auth::getUser()){
            $userId = Auth::getUser()->id;
            $reqs = Tech::where([
                    ['tech_user_id', '=', $userId],
                ])
                ->orderBy('id', 'desc')
                ->limit(100)
                ->get();

            return $reqs;
        }

    }
    public static function checkCounters($user){
        $checkCounters = Counters::where([
            ['user_id', '=', $user],
        ])->first();

        if (!$checkCounters){
            $sections = [
                "requests" => 0,
                "reserves" => 0,
                "inwork" => 0,
                "documents" => 0,
                "payhistory" => 0,
                "notifications" => 0,
            ];
            foreach ($sections AS $key => $val){
                $counter = new Counters;
                $counter->user_id = $user;
                $counter->element = $key;
                $counter->counter = $val;
                $counter->save();
            }
        }
        return true;
    }

    public static function addCounter($userId, $section){
        $counter =  Counters::where([
            ['user_id', '=', $userId],
            ['element', '=', $section]
        ])->first();
        $counter->counter = $counter->counter+1;
        $counter->save();
        return true;
    }

    public static function removeCounter($userId, $section){
        Counters::where([
            ['user_id', '=', $userId],
            ['element', '=', $section]
        ])->update(['counter' => 0]);
        return true;
    }

    public static function notificationsCount($section)
    {
        if (Auth::getUser()){
            $userId = Auth::getUser()->id;

            if ($section=='notifications'){
                Notify::where([
                    ['user_id', '=', $userId],
                ])->update(['viewed' => '1'] );
            }
            self::removeCounter($userId, $section);
            return true;
        }
    }
}
