<?php
/**
 * Copyright 2014 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 *  http://aws.amazon.com/apache2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */
namespace Amazon\InstantAccess\Serialization;

/**
 * The parent class of all serializable response classes.
 */
class InstantAccessResponse
{
    /** @var string */
    protected $response;

    /**
     * Serialize a {@see InstantAccessResponse} object to a JSON string. Take into account only public and protected
     * fields.
     *
     * @return string a JSON string
     */
    public function toJson()
    {
        return json_encode(get_object_vars($this));
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function setResponse($response)
    {
        $this->response = $response;
        return $this;
    }
}
