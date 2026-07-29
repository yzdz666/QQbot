<?php

class 画布
{
    public function 创建(int $宽, int $高, $hex = null) {
        $画布 = imagecreatetruecolor($宽, $高);
        imagealphablending($画布, true);
        imagesavealpha($画布, true);
        if ($hex === null) {
            $hex = "#FFFFFF";
        }
        $RGB = $this->HEX转RGB($hex);
        $颜色 = imagecolorallocatealpha($画布, $RGB[0], $RGB[1], $RGB[2], 0);
        imagefill($画布, 0, 0, $颜色);
        return $画布;
    }


    public function 销毁($画布) {
          return imagedestroy($画布);
    }
    
    
    public function 输出($画布) {
         header('Content-Type: image/png');
         return imagepng($画布,null,9);
    }
    
    
    public function 二进制输出($画布) {
        ob_start();
        imagepng($画布);
        $image = ob_get_clean();
        return $image;
    }
    
    
    public function HEX转RGB($hex) {
          $hex = str_replace('#', '', $hex);
          if (strlen($hex) == 3) {
              $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
          }
          $红 = hexdec(substr($hex, 0, 2));
          $绿 = hexdec(substr($hex, 2, 2));
          $蓝 = hexdec(substr($hex, 4, 2));
          return [0=>$红,1=>$绿,2=>$蓝];
    }
    
    public function 颜色($画布, $hex) {
          $j = $this->HEX转RGB($hex);
          return imagecolorallocate($画布, $j[0], $j[1], $j[2]);
    }
    
    
    public function 贴图($画布, $图片, $x, $y, $宽, $高, $透明度 = 100) {
        $图片数据 = file_get_contents($图片);
        $源图片 = imagecreatefromstring($图片数据);
        $缩放后图片 = imagecreatetruecolor($宽, $高);
        imagealphablending($缩放后图片, false);
        imagesavealpha($缩放后图片, true);
        imagecopyresampled(
            $缩放后图片, $源图片,
            0, 0, 0, 0,
            $宽, $高,
            imagesx($源图片), imagesy($源图片)
        );
        imagecopymerge($画布, $缩放后图片, $x, $y, 0, 0, $宽, $高, $透明度);
        imagedestroy($源图片);
        imagedestroy($缩放后图片);
    }
    
    public function 文字($画布, $文字, $大小, $x, $y, $颜色, $字体, $角度 = 0) {
        $颜色 = $this->颜色($画布, $颜色);
        imagettftext($画布, $大小, $角度, $x, $y, $颜色, $字体, $文字);
    }
    
    
    public function 直线($画布, $起点x, $起点y, $终点x, $终点y, $颜色) {
        $颜色 = $this->颜色($画布, $颜色);
        if (function_exists('imageantialias')) {
            imageantialias($画布, true);
        }
        return imageline($画布, $起点x, $起点y, $终点x, $终点y, $颜色);
    }
     
    
    public function 矩形($画布,$左上x,$左上y,$右下x,$右下y,$颜色,$透明度=0) {
        $颜色 = $this->颜色($画布, $颜色);
        if($透明度 > 0) {
            $透明色 = imagecolorallocatealpha($画布, $颜色>>16&0xFF, $颜色>>8&0xFF, $color&0xFF, 127*(100-$透明度)/100);
            return imagerectangle($画布, $左上x, $左上y, $右下x, $右下y, $透明色);
        }
        return imagerectangle($画布, $左上x, $左上y, $右下x, $右下y, $颜色);
    }

    public function 填充矩形($画布,$左上x,$左上y,$右下x,$右下y,$颜色,$透明度=0) {
        $RGB = $this->HEX转RGB($颜色);
        $alpha = 127*(100-$透明度)/100;
        $透明色 = imagecolorallocatealpha($画布, $RGB[0], $RGB[1], $RGB[2], $alpha);
        return imagefilledrectangle($画布, $左上x, $左上y, $右下x, $右下y, $透明色);
    }

    public function 圆($画布,$x,$y,$宽,$高,$颜色,$透明度=0) {
        $RGB = $this->HEX转RGB($颜色);
        $alpha = 127*(100-$透明度)/100;
        $透明色 = imagecolorallocatealpha($画布, $RGB[0], $RGB[1], $RGB[2], $alpha);
        return imageellipse($画布,$x,$y,$宽,$高,$透明色);
    }
    
    public function 填充圆($画布,$x,$y,$宽,$高,$颜色,$透明度=0) {
        $RGB = $this->HEX转RGB($颜色);
        $alpha = 127*(100-$透明度)/100;
        $透明色 = imagecolorallocatealpha($画布, $RGB[0], $RGB[1], $RGB[2], $alpha);
        return imagefilledellipse($画布,$x,$y,$宽,$高,$透明色);
    }

}