<?php 

require 'database.php';

$cloverData = [];
$sql = 'SELECT `merchantID`,`employee_id`,`token`,`status` FROM clover_merchant';

if ($result = mysqli_query($con, $sql))
{
    $i = 0;
    while($row = mysqli_fetch_assoc($result))
    {
        $cloverData[$i]['merchantID']   = $row['merchantID'];
        $cloverData[$i]['employee_id']  = $row['employee_id'];
        $cloverData[$i]['token']        = $row['token'];
        $cloverData[$i]['status']       = $row['status'];
        $i++;
    }
    http_response_code(404);
    echo json_encode($cloverData);
}
else
{
    http_response_code(404);
}