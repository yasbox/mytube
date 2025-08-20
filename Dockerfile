FROM php:8.2-apache

# システムパッケージの更新とFFmpegのインストール
RUN apt-get update && apt-get install -y \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Apache設定
RUN a2enmod rewrite

# 作業ディレクトリの設定
WORKDIR /var/www/html

# ポート80を公開
EXPOSE 80

# Apacheをフォアグラウンドで実行
CMD ["apache2-foreground"] 