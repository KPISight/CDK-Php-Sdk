<?php 

include_once __DIR__ . '/../Http/Request.php';
include_once __DIR__ . '/Extract/Extract.php';
include_once __DIR__ . '/../Response/Response.php';
include_once __DIR__ . '/Model/ServiceRo.php';
include_once __DIR__ . '/Model/Employee.php';
include_once __DIR__ . '/Model/Types.php';

class Core {

    public function __construct(
        public $authentication,
        public $environment,
        public $lines = false
    ){
        $this->setConfig();
        $this->http = new Http($this->configData());
        $this->extract = new Extract();
        $this->response = new Response();
        $this->serviceRo = new ServiceRo();
        $this->employee = new Employee();
        $this->types = new Types();
    }

    public function extract($data = [], $map = ['master' => [], 'prtextended' => []]){

        if(!isset($data['request'])){
            return $this->response->errorResponse("Missing 'request' param in SDK object.", false);
        }

        if(!isset($data['type'])){
            return $this->response->errorResponse("Missing 'type' param in SDK object.", false);
        }

        $cleanParams = [];
        foreach($data['request'] as $key => $value){
            $cleanParams = $this->extract->queryBuilder($cleanParams, [$key => $value]);
        }

        $response = $this->http->post($data['type'],$cleanParams);
        if(isset($response['status'])){
            return [
                'status' => 'error', 
                'message' => $response['message'],
                'raw-response' => $response
            ];
        }

        /**
         *  @ Setup Mappers ::
        */
        $feeMap = [];
        $prtsMap = [];
        if(isset($map['fee'])){
            $feeMap = $map['fee'];
        }
        if(isset($map['prtextended'])){
            $prtsMap = $map['prtextended'];
        }
        $map = $map['master'];

        $items = json_decode(
            json_encode(
                (array)simplexml_load_string($response['response'], 'SimpleXMLElement', LIBXML_NOCDATA)
        ), 
        true);

        $responseObj = $this->types->renderTypeObj($data['type']);
        if(!isset($items[$responseObj])){
            return [
                'status' => 'error',
                'message' => 'No data available for this request.',
                'returned' => $items,
                'xml-response' => $response['response'],
                'raw-response' => $response
            ];
        }


        $extractData = [];
        foreach($items[$responseObj] as $item){

            if($this->lines && (in_array($data['type'],$this->types->roServiceTypes())))
            {

                $extractParts = $this->parsePartsData($item,$prtsMap);

                if(isset($item[$this->serviceRo->LBRLINECODE]['V'])){
                    $lineCount = count((array)$item[$this->serviceRo->LBRLINECODE]['V']);
                    for($i=0;$i<$lineCount;$i++){
                        $extractData[] = $this->parseResponse($item,$map,$i,$extractParts);
                    }
                }
                
                if(isset($item[$this->serviceRo->FEEOPCODE]) && isset($item[$this->serviceRo->FEEOPCODE]['V'])){
                    $lineCount = count((array)$item[$this->serviceRo->FEEOPCODE]['V']);
                    for($i=0;$i<$lineCount;$i++){
                        $extractData[] = $this->parseResponse($item,$feeMap,$i,$extractParts,$this->serviceRo->feeOpCodeSkip(), true);
                    }
                }

            }else {
                $extractData[] = $this->parseResponseRaw($item,$map);
            }
        }
        return $extractData;
    }

    private function parsePartsData($item,$prtsMap){
        
        $keys = array_keys($item);

        $prtsExtendedCost = [];
        $prtsExtendedSale = [];
        $prtsLineCode = [];

        $prtsCost = [];
        $prtsSale = []; 

        foreach($keys as $key){
            
            if(isset($item[$key]['V']) && (!is_array($item[$key]['V']))){
                continue;
            }

            if(isset($item[$key]['V']) && ($key === $this->serviceRo->PRTEXTENDEDCOST)){
                foreach($item[$key]['V'] as $value){
                    $prtsExtendedCost[] = $value;
                }
            }
            if(isset($item[$key]['V']) && ($key === $this->serviceRo->PRTEXTENDEDSALE)){
                foreach($item[$key]['V'] as $value){
                    $prtsExtendedSale[] = $value;
                }
            }
            if(isset($item[$key]['V']) && ($key === $this->serviceRo->PRTLINECODE)){
                foreach($item[$key]['V'] as $value){
                    $prtsLineCode[] = $value;
                }
            }
        }

        $partsLineCodeList = [];
        foreach($prtsLineCode as $key => $lineCode){
            array_push($partsLineCodeList,$lineCode); 
        }

        $prtsCostSplit = [];
        $prtsSaleSplit = [];
        $l = 0;
        foreach($partsLineCodeList as $lineItem){
            $prtsCostSplit[$lineItem][] = $prtsExtendedCost[$l];
            $prtsSaleSplit[$lineItem][] = $prtsExtendedSale[$l];
            $l++;
        }

        $partsCostKeys = array_keys($prtsCostSplit);
        foreach($partsCostKeys as $p){
            $prtsCostSplit[$p] = number_format((float)array_sum($prtsCostSplit[$p]), 2, '.', '');
            $prtsSaleSplit[$p] = number_format((float)array_sum($prtsSaleSplit[$p]), 2, '.', '');
        }

        $prtsExtendedCostKey = $this->serviceRo->PRTEXTENDEDCOST;
        $prtsExtendedSaleKey = $this->serviceRo->PRTEXTENDEDSALE;
        foreach($prtsMap as $key => $value){
            if($prtsExtendedCostKey === $value){
                $prtsExtendedCostKey = $key;
            }
            if($prtsExtendedSaleKey === $value){
                $prtsExtendedSaleKey = $key;
            }
        }
        
        return [
            $prtsExtendedCostKey => $prtsCostSplit, 
            $prtsExtendedSaleKey => $prtsSaleSplit
        ];

    }

    private function parseResponse($data,$map,$number = 0,$extractParts = [], $ignored = [],$isFeeLine = false){

        $response = [];
        $fields = array_values($map);
        $keys = array_keys($map);
        $count = count($fields);

        for($i=0;$i<$count;$i++){

            if(in_array($keys[$i],$ignored)){
                $response[$fields[$i]] = '';
                continue;
            }

            if(in_array($keys[$i], array_keys($extractParts))){
                if(
                    in_array(
                        $data[$this->serviceRo->LBRLINECODE]['V'][$number],
                        array_keys($extractParts[$keys[$i]])
                    )
                    && (!$isFeeLine)
                )
                {
                    $response[$keys[$i]] = $extractParts[$keys[$i]][$data[$this->serviceRo->LBRLINECODE]['V'][$number]];
                }
                else {
                    $response[$keys[$i]] = 0;
                }
                continue;

            }

            if(isset($data[$fields[$i]]['V'])){
                if(is_array($data[$fields[$i]]['V'])){
                    if(isset($data[$fields[$i]]['V'][$number])){
                        $response[$keys[$i]] = $data[$fields[$i]]['V'][$number];
                    }
                }else {
                    $response[$keys[$i]] = $data[$fields[$i]]['V'];
                }
            }else {
                $response[$keys[$i]] = $this->convertBlankArrayData($data[$fields[$i]]);
            }
        }

        return $response;
    }


    private function parseResponseRaw($data,$map){
        $response = [];
        $fields = array_values($map);
        $keys = array_keys($map);
        $count = count($fields);
        for($i=0;$i<$count;$i++){
            $response[$keys[$i]] = $data[$fields[$i]];
        }
        return $response;
    }


    private function convertBlankArrayData($data){
        $isArray = is_array($data);
        if(!$isArray){
            return $data;
        }
        if(empty($data)){
            return '';
        }
        return $data;
    }




}