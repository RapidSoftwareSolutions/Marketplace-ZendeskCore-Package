<?php

namespace ZendeskCoreBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use ZendeskCoreBundle\Exception\PackageException;
use ZendeskCoreBundle\Exception\RequiredFieldException;

class DataValidator
{
    /** @var array */
    private $blockMetadata = [];

    /** @var array */
    private $requiredFieldError = [];

    /** @var array */
    private $parsedFieldError = [];

    /** @var Request */
    private $request;

    /** @var array */
    private $dataFromRequest = [];

    /** @var array */
    private $parsedValidData = [];

    /**
     * @param Request $request
     * @param array   $blockMetadata
     */
    public function setData(Request $request, $blockMetadata)
    {
        $this->request = $request;
        $this->blockMetadata = $blockMetadata;
        $this->setDataFromRequest();
        $this->parseDataFromRequest();
        $this->checkBlockMetadata();
    }

    /**
     * @return array
     */
    public function getValidData(): array
    {
        return $this->parsedValidData;
    }

    public function getBlockMetadata()
    {
        return $this->blockMetadata;
    }

    private function parseDataFromRequest()
    {
        foreach ($this->blockMetadata['args'] as $paramData) {
            if ($paramData['required'] == true) {
                $this->parseRequiredDataFromRequest($paramData);
            } else {
                $this->parseSingleDataFromRequest($paramData);
            }
        }
        $this->checkErrors();
    }

    private function checkErrors()
    {
        if (!empty($this->requiredFieldError)) {
            throw new RequiredFieldException(implode(',', $this->requiredFieldError));
        }
        if (!empty($this->parsedFieldError)) {
            throw new PackageException("Parse error in: " . implode(',', $this->parsedFieldError));
        }
    }

    private function parseRequiredDataFromRequest($paramData)
    {
        if ($this->checkNotEmptyParam($paramData)) {
            $this->parseSingleDataFromRequest($paramData);
        } else {
            $this->requiredFieldError[] = $paramData['name'];
        }
    }

    private function checkNotEmptyParam($paramData)
    {
        $name = $paramData['name'];
        $type = mb_strtolower($paramData['type']);
        $value = $this->getValueFromRequestData($name);
        if ($type == 'array') {
            if (!empty($value)) {
                return true;
            }
        } else {
            if (strlen(trim($value)) > 0) {
                return true;
            }
        }
        return false;
    }

    private function parseSingleDataFromRequest($paramData)
    {
        $name = $paramData['name'];
        $vendorName = $this->getParamVendorName($paramData);
        $type = mb_strtolower($paramData['type']);
        $value = $this->getValueFromRequestData($name);
        if (!empty($value)) {
            // todo add new metadata param "nullable" => true (default false) to send "" or "0" param
            switch ($type) {
                case 'json':
                    $this->setJSONValue($paramData, $value, $vendorName);
                    break;
                case 'array':
                    $this->setArrayValue($paramData, $value, $vendorName);
                    break;
                case 'boolean':
                    $this->setBooleanValue($paramData, $value, $vendorName);
                    break;
                case 'number':
                    $this->setIntValue($paramData, $value, $vendorName);
                    break;
                case 'file':
                    $this->setFileValue($paramData, $value, $vendorName);
                    break;
                default:
                    $this->setSingleValidData($paramData, $value, $vendorName);
                    break;
            }
        }
    }

    private function setSingleValidData($paramData, $value, $vendorName)
    {
        if (!empty($paramData['wrapName'])) {
            $wrapNameList = explode('.', $paramData['wrapName']);
            $this->addDepthOfNesting($this->parsedValidData, $wrapNameList, $value, $vendorName, $paramData);
        } else {
            $this->parsedValidData[$vendorName] = $value;
        }
    }

    private function addDepthOfNesting(array &$array, &$depthNameList, $value, $vendorName, $paramData)
    {
        $result = [];
        while (!empty($depthNameList)) {
            $deepName = array_shift($depthNameList);
            if (!isset($array[$deepName]) && !empty($depthNameList)) {
                $array[$deepName] = [];
            }
            if (empty($depthNameList)) {
                if (!empty($paramData['complex']) && filter_var($paramData['complex'], FILTER_VALIDATE_BOOLEAN) == true) {
                    $array[$deepName][] = $this->createComplexValue($paramData, $value, $vendorName);
                } else {
                    $array[$deepName][$vendorName] = $value;
                }
            }
            $result = $this->addDepthOfNesting($array[$deepName], $depthNameList, $value, $vendorName, $paramData);
        }

        return $result;
    }

    private function createComplexValue($paramData, $value, $vendorName)
    {
        return [
            $paramData['keyName'] => $vendorName,
            $paramData['keyValue'] => $value
        ];
    }

    /**
     * Return param Vendor name or change CamelCase to snake_case
     * @param array $paramData
     * @return string
     */
    private function getParamVendorName(array $paramData): string
    {
        if (!empty($paramData['vendorName'])) {
            return $paramData['vendorName'];
        } else {
            return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $paramData['name']));
        }
    }

    /**
     * @throws PackageException
     */
    private function setDataFromRequest()
    {
        $jsonContent = $this->request->getContent();
        if (empty($jsonContent)) {
            $this->dataFromRequest = $this->request->request->all();
        } else {
            $this->dataFromRequest = json_decode($jsonContent, true);
            if (json_last_error() != 0) {
                throw new PackageException(json_last_error_msg() . '. Incorrect input JSON. Please, check fields with JSON input.');
            }
        }
    }

    private function setJSONValue($paramData, $value, $vendorName)
    {
        $normalizeJson = $this->normalizeJson($value);
        $data = json_decode($normalizeJson, true);
        if (json_last_error()) {
            $this->parsedFieldError[] = $paramData['name'];
        } else {
            $this->setSingleValidData($paramData, $data, $vendorName);
        }
    }

    private function setFileValue($paramData, $value, $vendorName)
    {
        if (isset($paramData['jsonParse']) && filter_var($paramData['jsonParse'], FILTER_VALIDATE_BOOLEAN) == true) {
            $content = file_get_contents($value);
            $this->setJSONValue($paramData, $content, $vendorName);
        } else {
            if (isset($this->blockMetadata['type']) && $this->blockMetadata['type'] == 'multipart') {
                $content = fopen($value, 'r');
            }
            else {
                $content = file_get_contents($value);
                if (isset($paramData['base64encode']) && filter_var($paramData['base64encode'], FILTER_VALIDATE_BOOLEAN) == true) {
                    $content = base64_encode($content);
                }
            }
        }
        $this->setSingleValidData($paramData, $content, $vendorName);
    }

    private function setArrayValue($paramData, $value, $vendorName)
    {
        if (mb_strtolower($this->blockMetadata['method']) == 'get') {
            $data = is_array($value) ? implode(',', $value) : $value;
            $this->setSingleValidData($paramData, $data, $vendorName);
        } else {
            $data = is_array($value) ? $value : explode(',', $value);
            $this->setSingleValidData($paramData, $data, $vendorName);
        }
    }

    private function setBooleanValue($paramData, $value, $vendorName)
    {
        $data = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        if (!empty($paramData['toInt']) && filter_var($paramData['toInt'], FILTER_VALIDATE_BOOLEAN) == true) {
            $data = (int) $data;
        }
        $this->setSingleValidData($paramData, $data, $vendorName);
    }

    private function setIntValue($paramData, $value, $vendorName)
    {
        $data = (int) $value;
        $this->setSingleValidData($paramData, $data, $vendorName);
    }

    private function checkBlockMetadata()
    {
        if (!isset($this->blockMetadata['url'])) {
            throw new PackageException("Cant find part of vendor's endpoint");
        }
        if (!isset($this->blockMetadata['method'])) {
            throw new PackageException("Cant find method of vendor's endpoint");
        }
    }

    private function normalizeJson($jsonString)
    {
        $data = preg_replace_callback('~"([\[{].*?[}\]])"~s', function ($match) {
            return preg_replace('~\s*"\s*~', "\"", $match[1]);
        }, $jsonString);

        return str_replace('\"', '"', $data);
    }

    private function getValueFromRequestData($paramName)
    {
        if (isset($this->dataFromRequest['args'][$paramName])) {
            return $this->dataFromRequest['args'][$paramName];
        }
        return null;
    }
}