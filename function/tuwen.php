<?php

function 图文($text) {
$text=str_replace("[加号]","+",$text);
$text=str_replace("[井号]","#",$text);
$ttf=__DIR__."/font/雅黑.ttf";
if (!file_exists($ttf)) {
    $fallbacks=[
        '/system/fonts/DroidSansFallback.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        __DIR__."/font/msyh.ttf",
    ];
    foreach ($fallbacks as $fallback) {
        if (file_exists($fallback)) {
            $ttf=$fallback;
            break;
        }
    }
}
$font_size=25;
$data=explode("\n",$text);
$arrays=array();
$isheight=0;
foreach($data as $r)
{
$arrays[]=mb_strlen($r, 'UTF-8');
$isheight=$isheight+1;
}
foreach($arrays as $k=>$f)
{
if($f==max($arrays))
{
$msgaa=$data[$k];
}
}
$count=count($data);
$wide=imagettfbbox($font_size,0,$ttf,$msgaa);
$height=((count($data)*($font_size*2))-10);
//$height=(count($data)*10);
$width=($wide[2]+($font_size/2)+2);
$image=imagecreatetruecolor($width,$height);
$White = imagecolorallocate($image,250,250,250);
imagefill($image,0,0,$White);
$textheight=(($font_size-10)-(($font_size-10))*2);
//$textheight=2;
foreach($data as $v)
{
$fontbox=imagettfbbox($font_size,0,$ttf,$v);
//print_r($rgb);
$color=imagecolorallocate($image,0,0,0);
$textheight=($textheight+($font_size*2));
imagettftext($image,$font_size,0,2,$textheight,$color,$ttf,$v);
}
//echo "有{$count}行字符<br>";
//echo "计算高度:{$height}<br>";
//echo "计算宽度:{$width}<br>";
//echo "已用高度:{$isheight}";
ob_start();
imagepng($image);
$image_data = ob_get_clean();
imagedestroy($image);
return $image_data;
}
