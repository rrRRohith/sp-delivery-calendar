<?php

use App\Models\Menu;
use App\Models\Store;
use App\Models\SocialmediaSite;
use App\Models\Basket;
use App\Models\Item;
use App\Models\Holiday;
use App\Models\VariationImage;
use App\Models\ProductImage;
use Carbon\Carbon;
use Faker\Factory;
use App\Models\ProductVariation;
use App\Models\Shipping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;


/*****************************************************************************/
function roundTimeToNearestInterval($timestamp, $interval) {
    // Calculate the number of intervals since Unix epoch
    $roundedTimestamp = round($timestamp / $interval) * $interval;
    return $roundedTimestamp;
}

function ShippingMethodFinding($delivery_type){
   
    $session_string = session('session_string');						
    $basket_items   = Basket::where('session',$session_string)->where('status',0)->first();
    $items          = Item::with('productShipping','productShipping.shipping')->where('basket_id',$basket_items->id)->get();
    $maxShippingDays = [];

    $maxDaysshippingId = '';
    $maxDays           = 0;
    $maxPrepration     = 0;
    $mostPriority      = 0;
        
    foreach ($items as $item) {
        
        // Iterate through the product shipping methods for the item
        foreach ($item->productShipping as $productShipping) {
          
            $shippingMethod = $productShipping->shipping;
            // $shippingMethod = $productShipping->shipping->where(function ($query) use ($delivery_type) {
            //                                         $query->where('order_type', $delivery_type)
            //                                               ->orWhere('order_type', '=', 'both');
            //                                     })
            //                                     ->where('name','!=','Pickup')
            //                                     ->where('name','!=','Delivery')->get();
                                  
            
            if($shippingMethod){                           
                if ($shippingMethod->name != 'Pickup' && $shippingMethod->name != 'Delivery' && ($shippingMethod->order_type == $delivery_type || $shippingMethod->order_type == 'both')) {
                        // Check if the shipping method has a delivery days attribute
                    // if ($shippingMethod && $shippingMethod->preperation_time > $maxPrepration) {
                    //     $maxDays = $shippingMethod->days;
                    //     $maxPrepration = $shippingMethod->preperation_time;
                    //     $maxDaysshippingId = $shippingMethod->id;
                    //     dd($shippingMethod);
                    // }
                     if ($shippingMethod && $shippingMethod->priority > $mostPriority) {
                        $maxDays = $shippingMethod->days;
                        $mostPriority = $shippingMethod->priority;
                        $maxDaysshippingId = $shippingMethod->id;
                        
                    }
                }
            }
        }
    
        // Store the maximum days for this item
        // $maxShippingDays[$item->id] = $maxDays;
    }
    
    return $maxDaysshippingId;
}


/*****************************SHIPPING RULE BASED NEW CALENDER************************************************/

/****************** PICKUP CALENDER OR PREORDER PICKUP CALENDER *******************************/

function ShippingRulePickupBasedCalender($store_id = null,$type = null,$start_date = null, $end_date = null){
    
    $maxDaysshippingId = ShippingMethodFinding('pickup');
    if($maxDaysshippingId != NULL){
        $shipping   = Shipping::with('rules')->where('id', $maxDaysshippingId)->first();
    }
    else{
        $shipping   = Shipping::with('rules')->where('order_type', 'pickup')->first();
    }
    
    $cuttoff    = $shipping->cut_off ?? strtotime('6:00 PM');
    $store      = Store::with('store_timing', 'holidaytiming', 'holidaytiming.holiday')->where('status',1)->where('id', $store_id)->first();
    $preparetime= $shipping->preperation_time * 3600 ?? env('PREPARATION_TIME');
    
    $preSkipDay = intval($preparetime/86400);
    // $preSkipDay = 0;
    
    // $allow_after_days = $shipping->days ?? 1;
    
    $allow_after_days = 0;
    
    if(isset($_REQUEST['dt'])) {
        $currentDate = Carbon::createFromFormat('Y-m-d H:i:s', $_REQUEST['dt'].' '.(isset($_REQUEST['tm']) ? $_REQUEST['tm']:date('H:i:s')));
    }
    else {
        $currentDate =  now();
    }
    
    $firstMonth = $currentDate->copy();
    // $secondMonth = $currentDate->copy()->addMonth();
    $secondMonth = $currentDate->copy()->firstOfMonth()->addMonthNoOverflow();
    
    $holidays = Holiday::with('holidaytiming')->where('the_date','>=',date('Y-m-d'))->pluck('the_date')->toArray();

    $result = '<div id="calendar-dropdown" class="position-absolute bg-light d-none text-center">
            <div class="calendar">';
    
    for ($month = 0; $month < 2; $month++) {
        // $currentMonth = $currentDate->copy()->addMonth($month);  
        $currentMonth = $month === 0 ? $firstMonth : $secondMonth;
        $sec_month = $month === 1 ? 'd-none' : '';

        $result .= '
            <div class="month month-'.$month.' '.$sec_month.'">
                <h6 class="text-center fw-bolder mt-1 mb-1">' . $currentMonth->format('F Y') . '</h6>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th scope="col">Sun</th>
                            <th scope="col">Mon</th>
                            <th scope="col">Tue</th>
                            <th scope="col">Wed</th>
                            <th scope="col">Thu</th>
                            <th scope="col">Fri</th>
                            <th scope="col">Sat</th>
                        </tr>
                    </thead>
                    <tbody>';
    
        $firstDayOfMonth    = $currentMonth->copy()->startOfMonth();
        $lastDayOfMonth     = $currentMonth->copy()->endOfMonth();
        $startDayOfWeek     = $firstDayOfMonth->dayOfWeek;
        $currentDate        = $firstDayOfMonth->copy()->subDays($startDayOfWeek);
        $today              = \Carbon\Carbon::today();
        $today_day          = $today->format('w');
        $isDisabled         = $currentDate->lt($today);
        $currentDateTime    = Carbon::now();
        $currentTime        = strtotime($currentDateTime->toDateTimeString());
        $hasafterAvailable  = false;
            
        while ($currentDate <= $lastDayOfMonth) {
            $result .= '<tr>';
            
            for ($i = 0; $i < 7; $i++) {
                $result .= '<td>';
                if ($currentDate->month === $currentMonth->month && $currentDate->gte($firstDayOfMonth)) {
                    $date = $currentDate->format('Y-m-d');
                    $day_number = $currentDate->format('w');
                    $day = strtolower($currentDate->format('l'));
                    $isHoliday = in_array($date, $holidays);
                    
                    if($preSkipDay > 0){
                        $allow_after = $today->copy()->addDay($allow_after_days+$preSkipDay);
                    }
                    else{
                        $allow_after = $today->copy()->addDay($allow_after_days);  
                    }
                    
                    
                    $isDisabled = $currentDate->lt($allow_after);
                    $isToday = $currentDate->isSameDay($today);
                   
                    $store_workingday = $store->store_timing->where('day', $day_number)->first();
                    
                    
                    if ($store_workingday) {
                        $opening_time = strtotime($store_workingday->open);
                        $availableTime_on = strtotime($store_workingday->open)+ $preparetime;
                        $availableTime_to = strtotime($store_workingday->close);
                        
                        // $close_time = $store_workingday->close;
                        // $availableTime_to = strtotime($store_workingday->close) - $preparetime;
                        // $availableTime_to = strtotime($cuttoff);
                    }
                    
                    
                    $shippingRule = $shipping->rules->where('day',$day)->first();
                    
                            
                    
    
                    $isAvailable = true;
                    $isTimeExceeded = false;
                    
                     $currentDayOfWeek = strtolower($currentDate->format('l'));
    
                    if($shipping->{$currentDayOfWeek} == 0){
                        $isDisabled = true;
                    }
                    
                    ///////////////Manual date skip code/////////////////////////////////
                    
                    // if(time() >= strtotime('2023-12-18 17:55:00') && ($currentDate->format('Y-m-d') == '2023-09-18' || $currentDate->format('Y-m-d') == '2023-09-19')) {
                    //     $isDisabled = true;
                    // }
                   
                   
                     if($start_date != null &&  $end_date != null){
                         // Convert start and end dates to Carbon instances
                        $startDate          = \Carbon\Carbon::parse($start_date);
                        $endDate            = \Carbon\Carbon::parse($end_date);
                        if($currentDate < $startDate || $currentDate > $endDate) {
                            $isDisabled = true;
                        }
                    }
                    
                    if($isToday && date('Y-m-d') == '2023-12-24') {
                        $isDisabled = true;
                    }
                    
                    if($currentDate->format('Y-m-d') == '2024-10-11' || $currentDate->format('Y-m-d') == '2024-10-12' || $currentDate->format('Y-m-d') == '2024-10-13') {
                         $isDisabled = true;
                    }
                    
                    
                    if($isToday){
                                if(strtotime(date("H:i")) <= strtotime($shippingRule->cutoff)){
                                   
                                    if($shippingRule->before_day == 0){
                                        if(strtotime(date("H:i")) <= $opening_time){
                                            $timeStrem = $opening_time; // store open time
                                        }
                                        else{
                                            $timeStrem = strtotime(date("H:i"));    // current time
                                        }
                                    
                                        $startingTime = date('H:i',$timeStrem + $preparetime);  // time + prep time
                                           
                                        if(strtotime($startingTime) <= $availableTime_to){
                                            // if($startingTime > $opening_time){
                                             if(strtotime($startingTime) > $opening_time){
                                                $RoundavailableTime_on = strtotime($startingTime);
                                                $availableTime_on = roundTimeToNearestInterval($RoundavailableTime_on, 900);
                                            }
                                            
                                            if($availableTime_on <= $availableTime_to){
                                                //$result .= '<span day="'.$currentDate->format('l').'" class="date today valid_date" data-start="'.date('H:i',$availableTime_on).'" data-end="'.date('H:i',$availableTime_to).'" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                                            } 
                                            else {
                                                $isTimeExceeded = true;
                                            }
                                    
                                        }
                                        else{
                                            $isTimeExceeded = true;
                                        }
                                    }
                                    else{
                                       $isTimeExceeded             = true;
                                        $hasafterAvailable          = true;
                                        $afterAvailable             = $today->copy()->addDay($shippingRule->before_day + $preSkipDay); 
                                        $openingTimeAfterCuttoff    = strtotime($shippingRule->before_time); 
                                    }
                                    
                                }
                                else {
                             
                                    $isTimeExceeded             = true;
                                    $hasafterAvailable          = true;
                                    $afterAvailable             = $today->copy()->addDay($shippingRule->after_day + $preSkipDay); 
                                    $openingTimeAfterCuttoff    = strtotime($shippingRule->after_time);
                                           
                                }
                                
                               
                            }
                    
                    if(isset($afterAvailable) && $afterAvailable->gt($currentDate)) {
                        
                        $isDisabled = true;
                    }
                    
                    if($hasafterAvailable == true && $afterAvailable->format('Y-m-d') > $currentDate->format('Y-m-d')) {
                        $isDisabled = true;
                    }
                    elseif($hasafterAvailable == true && $afterAvailable->format('Y-m-d') == $currentDate->format('Y-m-d')){
                        // $availableTime_on = $openingTimeAfterCuttoff+ $preparetime;
                        if($availableTime_on <= $openingTimeAfterCuttoff){
                            $availableTime_on = $openingTimeAfterCuttoff + $preparetime;
                        }
                      
                    } 
                    
                    ///////////////Manual date skip code end/////////////////////////////////
    // && !$isDisabled
                    if (!$isHoliday && $store_workingday ) {
                        
                            if($isToday){
                                if(strtotime(date("H:i")) <= strtotime($shippingRule->cutoff)){
                                   
                                    if($shippingRule->before_day == 0){
                                        if(strtotime(date("H:i")) <= $opening_time){
                                            $timeStrem = $opening_time; // store open time
                                        }
                                        else{
                                            $timeStrem = strtotime(date("H:i"));    // current time
                                        }
                                    
                                        $startingTime = date('H:i',$timeStrem + $preparetime);  // time + prep time
                                           
                                        if(strtotime($startingTime) <= $availableTime_to){
                                            // if($startingTime > $opening_time){
                                             if(strtotime($startingTime) > $opening_time){
                                                $RoundavailableTime_on = strtotime($startingTime);
                                                $availableTime_on = roundTimeToNearestInterval($RoundavailableTime_on, 900);
                                            }
                                            
                                            if($availableTime_on <= $availableTime_to){
                                                $result .= '<span day="'.$currentDate->format('l').'" class="date today valid_date" data-start="'.date('H:i',$availableTime_on).'" data-end="'.date('H:i',$availableTime_to).'" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                                            } 
                                            else {
                                                $isTimeExceeded = true;
                                            }
                                    
                                        }
                                        else{
                                            $isTimeExceeded = true;
                                        }
                                    }
                                    else{
                                       $isTimeExceeded             = true;
                                        $hasafterAvailable          = true;
                                        $afterAvailable             = $today->copy()->addDay($shippingRule->before_day + $preSkipDay); 
                                        $openingTimeAfterCuttoff    = strtotime($shippingRule->before_time); 
                                    }
                                    
                                }
                                else {
                             
                                    $isTimeExceeded             = true;
                                    $hasafterAvailable          = true;
                                    $afterAvailable             = $today->copy()->addDay($shippingRule->after_day + $preSkipDay); 
                                    $openingTimeAfterCuttoff    = strtotime($shippingRule->after_time);
                                           
                                }
                            }
                            
                            
                        
                    }
    
                    if ($isHoliday) {
                        $holi_Day = Holiday::where('the_date', $date)->first();
                        $checkStoreAvailability = Holiday::leftJoin('holiday_timings', 'holiday_timings.holiday_id', 'holidays.id')
                                                        ->where('store_id', $store_id)
                                                        ->where('the_date', $date)
                                                        ->first();
                                
                        if (!$checkStoreAvailability || $isDisabled) {
                            $result .= '<span day="'.$currentDate->format('l').'" title="' . $holi_Day->name . '- Store off day" class="date holiday">' . $currentDate->format('j') . '</span>';
                        } 
                        elseif($isToday && strtotime($checkStoreAvailability->cut_off) <= time()){
                            $result .= '<span day="'.$currentDate->format('l').'" title="Time exceeded. Today is not available" class="date holiday disabled" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                        }
                        else {
                            
                           
                            
                            
                            $opening_time = $checkStoreAvailability->online_pickup_open ?? $checkStoreAvailability->open;
                            $close_time = $checkStoreAvailability->online_pickup_close ?? $checkStoreAvailability->close;
                            // $close_time = $cuttoff;
                            
                            if($opening_time != null){
                                if($opening_time == null){
                                        $opening_time = $store_workingday->open;
                                }
                            
                                if($close_time == null){
                                    // $close_time = $store_workingday->close ?? '00:00';
                                    $close_time = $cuttoff;
                                }
                    
                                $availableTime_on = strtotime($opening_time) + $preparetime;
                                $availableTime_to = strtotime($close_time);
                                   
                                if($isToday){
                                    $currentTime = $currentTime + $preparetime;
                                }
                           
                                if($isToday && $currentTime > $opening_time && $currentTime <= $availableTime_to){
                                    $RoundavailableTime_on = $currentTime;
                                    $availableTime_on = roundTimeToNearestInterval($RoundavailableTime_on, 900);
                                    $result .= '<a href="#" title="' . $holi_Day->name . '" class="valid_date date holiday-allow" data-start="'.date('H:i',$availableTime_on).'" data-end="'.date('H:i',$availableTime_to).'"  data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</a>';
                                  
                                }
                                elseif($isToday && $currentTime > $availableTime_to){
                              
                                    $result .= '<span day="'.$currentDate->format('l').'" title="Time exceeded. Today is not available" class="date holiday disabled" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                                }
                                else{
                                   
                                    $result .= '<a href="#" title="' . $holi_Day->name . '" class="valid_date date holiday-allow" data-start="'.date('H:i',$availableTime_on).'" data-end="'.date('H:i',$availableTime_to).'"  data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</a>';
                                }
                            }
                            else{
                        
                                $result .= '<span day="'.$currentDate->format('l').'" title="Store not available" class="date holiday disabled">' . $currentDate->format('j') . '</span>';
                            }
                        }
                    } elseif ($isDisabled || !$store_workingday) {
                        $thxBlock = \Carbon\Carbon::now()->gt(\Carbon\Carbon::parse('2024-10-11 15:00')) && \Carbon\Carbon::now()->lt(\Carbon\Carbon::parse('2024-10-14 24:00'));
                        $result .= '<span day="'.$currentDate->format('l').'" title="Store not available" class="date disabled '.($thxBlock ? "thanksblock" : "").'">' . $currentDate->format('j') . '</span>';
                    } elseif ($isAvailable && !$isToday) {
                        $result .= '<a href="#" class="valid_date date" data-start="'.date('H:i',$availableTime_on).'" data-end="'.date('H:i',$availableTime_to).'"  data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</a>';
                    } elseif ($isTimeExceeded) {
                        $result .= '<span day="'.$currentDate->format('l').'" title="Time exceeded. Today is not available" class="date holiday disabled'; 
                        if($isToday){
                           $result .=' today ';
                        } 
                        $result .='" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                    }
                } else {
                    $thxBlock = \Carbon\Carbon::now()->gt(\Carbon\Carbon::parse('2024-10-11 16:00')) && \Carbon\Carbon::now()->lt(\Carbon\Carbon::parse('2024-10-13 24:00'));
                    $result .= '<span day="'.$currentDate->format('l').'" class="date disabled '.($thxBlock ? "thanksblock" : "").' ">' . $currentDate->format('j') . '</span>';
                }
    
                $result .= '</td>';
                $currentDate = $currentDate->addDay();
            }
            $result .= '</tr>';
        }
    
        $result .= '</tbody>
                </table>
            </div>';
    }
    
    
    $result .= '</div>
            <span class="text-center cursor-pointer fw-bold show-more-dates">More dates</a></div>';
    
    return $result;
}



/*************** DELIVERY CALENDAR OR PREORDER PICKUP *****************/
function ShippingRuleDeliveryBasedCalender($start_date = null, $end_date = null,$store_id = null,$type = null){
    $maxDaysshippingId = ShippingMethodFinding('delivery');
    if($maxDaysshippingId != NULL){
        $shipping   = Shipping::where('id', $maxDaysshippingId)->first();
    }
    else{
        $shipping   = Shipping::where('order_type', 'delivery')->first();
    }

    $preparetime= $shipping->preperation_time * 3600 ?? env('PREPARATION_TIME');
    $preSkipDay = intval($preparetime/86400);
            
    $cuttoff = strtotime($shipping->cut_off) ?? strtotime('2:30 PM');
    // $allow_after_days = $shipping->days ?? 0;
    $allow_after_days = 0;
  
    
    
    $currentDate = now();
    $firstMonth = $currentDate->copy();
    // $secondMonth = $currentDate->copy()->addMonth();
    $secondMonth = $currentDate->copy()->firstOfMonth()->addMonthNoOverflow();
    $holidays = Holiday::with('holidaytiming')->where('the_date','>=',date('Y-m-d'))->pluck('the_date')->toArray();
    
    $result = '<div id="calendar-dropdown" class="position-absolute bg-light d-none text-center">
        <div class="calendar">';
    for ($month = 0; $month < 2; $month++) {
        $currentMonth = $month === 0 ? $firstMonth : $secondMonth;
        $sec_month = $month === 1 ? 'd-none' : '';
        $result .= '
            <div class="month month-'.$month.' '.$sec_month.'">
                <h6 class="text-center fw-bolder mt-1 mb-1">' . $currentMonth->format('F Y') . '</h6>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th scope="col">Sun</th>
                            <th scope="col">Mon</th>
                            <th scope="col">Tue</th>
                            <th scope="col">Wed</th>
                            <th scope="col">Thu</th>
                            <th scope="col">Fri</th>
                            <th scope="col">Sat</th>
                        </tr>
                    </thead>
                    <tbody>';
    
        $firstDayOfMonth    = $currentMonth->copy()->startOfMonth();
        $lastDayOfMonth     = $currentMonth->copy()->endOfMonth();
        $startDayOfWeek     = $firstDayOfMonth->dayOfWeek;
        $currentDate        = $firstDayOfMonth->copy()->subDays($startDayOfWeek);
        $today              = \Carbon\Carbon::today();
        $today_day          = $today->format('w');
        $isDisabled         = $currentDate->lt($today);
        $tomorrow           = $today->copy()->addDay();
        $tomorrow_day       = $tomorrow->format('w');
        $currentDateTime    = Carbon::now();
        $currentTime        = strtotime($currentDateTime->toDateTimeString());

        $hasafterAvailable  = false;
        
        while ($currentDate <= $lastDayOfMonth) {
            $result .= '<tr>';
            for ($i = 0; $i < 7; $i++) {
                $result .= '<td>';
                if ($currentDate->month === $currentMonth->month && $currentDate->gte($firstDayOfMonth)) {
                    $date           = $currentDate->format('Y-m-d');
                    $day_number     = $currentDate->format('w');
                    $day_name       = strtolower($currentDate->format('l'));
                    $isHoliday      = in_array($date, $holidays);
    
                    if($preSkipDay > 0){
                        $allow_after = $today->copy()->addDay($allow_after_days+$preSkipDay);
                    }
                    else{
                        $allow_after = $today->copy()->addDay($allow_after_days);  
                    }
                    
                      
                    // $allow_after = $today->copy()->addDay($allow_after_days);
                    $isDisabled     = $currentDate->lt($allow_after);
                    $isTomorrow     = $currentDate->isSameDay($tomorrow);
                    $isToday        = $currentDate->isSameDay($today);
                    $day_available  = $shipping->$day_name == 1;
    
                    $isAvailable    = true;
                    $isTimeExceeded = false;
                    
                    $shippingRule   = $shipping->rules->where('day',$day_name)->first();
                    
                    $currentDayOfWeek = strtolower($currentDate->format('l'));
    
                    // if($shipping->{$currentDayOfWeek} == 0){
                    //     $isDisabled = true;
                    // }
                    
                    
                    if($start_date != null &&  $end_date != null){
                         // Convert start and end dates to Carbon instances
                        $startDate          = \Carbon\Carbon::parse($start_date);
                        $endDate            = \Carbon\Carbon::parse($end_date);
                        if($currentDate < $startDate || $currentDate > $endDate) {
                            $isDisabled = true;
                        }
                    }

                     $currentDayOfWeek = strtolower($currentDate->format('l'));
    
                    if($shipping->{$currentDayOfWeek} == 0){
                        $isDisabled = true;
                    }
                    
                    // if($shippingRule->status == 0){
                    //     $isDisabled = true;
                    // }
                    
                    if ($isToday){  
                            
                                if(strtotime(date("H:i")) <= strtotime($shippingRule->cutoff)){
                                    if($shippingRule->before_day == 0){
                                        $result .= '<span day="'.$currentDate->format('l').'" class="date today valid_date" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                                    }
                                    else{
                                       $isTimeExceeded             = true;
                                        $hasafterAvailable          = true;
                                        $afterAvailable             = $today->copy()->addDay($shippingRule->before_day+$preSkipDay); 
                                        $openingTimeAfterCuttoff    = strtotime($shippingRule->before_time); 
                                    }
                                }
                                else {
                                 
                                    $isTimeExceeded             = true;
                                    $hasafterAvailable          = true;
                                    $afterAvailable             = $today->copy()->addDay($shippingRule->after_day+$preSkipDay); 
                                    $openingTimeAfterCuttoff    = strtotime($shippingRule->after_time);
                                    
                                }
                                
                        }
                        
                    if(isset($afterAvailable) && $afterAvailable->gt($currentDate)) {
                        
                        $isDisabled = true;
                    }
                    
                    if($hasafterAvailable == true && $afterAvailable->format('Y-m-d') > $currentDate->format('Y-m-d')) {
                        $isDisabled = true;
                    }
                    elseif($hasafterAvailable == true && $afterAvailable->format('Y-m-d') == $currentDate->format('Y-m-d')){
                        $availableTime_on = $openingTimeAfterCuttoff+ $preparetime;
                    }
    // && !$isDisabled
  
     
                    if (!$isHoliday && $day_available ) {
                        
                        if ($isToday){  
                            
                                if(strtotime(date("H:i")) <= strtotime($shippingRule->cutoff)){
                                    if($shippingRule->before_day == 0){
                                        $result .= '<span day="'.$currentDate->format('l').'" class="date today valid_date" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                                    }
                                    else{
                                       $isTimeExceeded             = true;
                                        $hasafterAvailable          = true;
                                        $afterAvailable             = $today->copy()->addDay($shippingRule->before_day+$preSkipDay); 
                                        $openingTimeAfterCuttoff    = strtotime($shippingRule->before_time); 
                                    }
                                }
                                else {
                                 
                                    $isTimeExceeded             = true;
                                    $hasafterAvailable          = true;
                                    $afterAvailable             = $today->copy()->addDay($shippingRule->after_day+$preSkipDay); 
                                    $openingTimeAfterCuttoff    = strtotime($shippingRule->after_time);
                                    
                                }
                                
                        }
                        
                       
                    }
                     
                    
    
                    if ($isHoliday) {
                        $holi_Day = Holiday::where('the_date', $date)->first();
                        $checkStoreAvailability = Holiday::where('the_date', $date)->where('block_delivery', 0)->first();
    
                        if (!$checkStoreAvailability || $isDisabled) {
                            $result .= '<span day="'.$currentDate->format('l').'" title="' . $holi_Day->name . '- Off day" class="date holiday">' . $currentDate->format('j') . '</span>';
                        } else {
                            $holi_cuttoff = strtotime($holi_Day->cut_off);
                            
                            if($isToday){
                                $currentTime = $currentTime + $preparetime;
                            }
                           
                            if($isToday && $currentTime > $opening_time && $currentTime <= $holi_cuttoff){
                                $RoundavailableTime_on = $currentTime;
                                $availableTime_on = roundTimeToNearestInterval($RoundavailableTime_on, 900);
                                $result .= '<a href="#" title="' . $holi_Day->name . '" class="valid_date date holiday-allow"  data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</a>';
                              
                            }
                            elseif($isToday && $currentTime > $holi_cuttoff){
                          
                                $result .= '<span day="'.$currentDate->format('l').'" title="Time exceeded. Today is not available" class="date holiday disabled" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                            }
                            else{
                               
                                $result .= '<a href="#" title="' . $holi_Day->name . '" class="valid_date date holiday-allow"   data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</a>';
                            }
    
                            // if ($currentTime < $holi_cuttoff) {
                            //     $result .= '<a href="#" title="' . $holi_Day->name . '" class="valid_date date holiday-allow" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</a>';
                            // } else {
                            //     $result .= '<span day="'.$currentDate->format('l').'" title="Time exceeded. Date not available" class="date holiday disabled " data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                            // }
                        }
                    } elseif ($isDisabled && $isToday) {
                        $result .= '<span day="'.$currentDate->format('l').'" class="date today disabled" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                    } elseif ($isDisabled || !$day_available) {
                        $thxBlock = \Carbon\Carbon::now()->gt(\Carbon\Carbon::parse('2024-10-11 16:00')) && \Carbon\Carbon::now()->lt(\Carbon\Carbon::parse('2024-10-13 24:00'));
                        $result .= '<span day="'.$currentDate->format('l').'" title="Off day.." class="date disabled '.($thxBlock ? "thanksblock" : "").'">' . $currentDate->format('j') . '</span>';
                    } elseif ($isAvailable && !$isToday) {
                        $result .= '<a href="#" class="valid_date date" data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</a>';
                    } elseif ($isTimeExceeded) {
                        $result .= '<span day="'.$currentDate->format('l').'" title="Time exceeded. This day  not available" class="date  disabled " data-date="' . $currentDate->format('Y-m-d') . '">' . $currentDate->format('j') . '</span>';
                    }
                } else {
                    $thxBlock = \Carbon\Carbon::now()->gt(\Carbon\Carbon::parse('2024-10-11 16:00')) && \Carbon\Carbon::now()->lt(\Carbon\Carbon::parse('2024-10-13 24:00'));
                    $result .= '<span day="'.$currentDate->format('l').'" class="date disabled '.($thxBlock ? "thanksblock" : "").'">' . $currentDate->format('j') . '</span>';
                }
    
                $result .= '</td>';
                $currentDate = $currentDate->addDay();
            }
            $result .= '</tr>';
        }
    
        $result .= '</tbody>
                </table>
            </div>';
    }
    
    $result .= '</div>
    
            <span class="text-center cursor-pointer fw-bold show-more-dates">More dates</a>
            </div>';

    return $result;
}


/*****************************************************************************/
