const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const LoadmoreGroupPageController = async (ctx, data, io,socket,callback) => {
  let fromUser = await ctx.wo_users.findOne({
      where: {
          user_id: {
              [Op.eq]: ctx.userHashUserId[data.from_id]
          }
      }
  })
  let messages = await ctx.wo_messages.findAll({
      limit: 15,
      where: {
          id: {
              [Op.lt]: data.before_message_id
          },
          [Op.or]: [
              {
                  from_id: {
                      [Op.eq]: ctx.userHashUserId[data.from_id]
                  },
                  group_id: {
                      [Op.eq]: data.group_id
                  }
              },
              {
                  group_id: {
                      [Op.eq]: data.group_id
                  },
                  to_id: {
                      [Op.eq]: ctx.userHashUserId[data.from_id]
                  }
              }
          ]
      },
      order: [['id', 'DESC']]
  })
  let html = ""
  for (let message of messages) {
      if (message.media && message.media != "") {
          let d = { ...data }
          d.mediaId = message.id;
          if (message.from_id === ctx.userHashUserId[data.from_id]) {
              html += await compiledTemplates.messageListOwnerTrueWithMedia(ctx, d, fromUser, message, true, data.color, data.isSticker)
          }
          else {
              html += await compiledTemplates.messageListOwnerFalseWithMedia(ctx, d, message, fromUser, data.isSticker)
          }
      } else {
          let hasHTML = message.text.split(" ").includes("<i")
          let msg = message.text;
          // ({ msg, hasHTML } = funcs.Wo_Emo(message.text))
          // message.text = msg
          let link_regex = new RegExp('(http\:\/\/|https\:\/\/|www\.)([^\ ]+)', 'gi');
          let mention_regex = new RegExp('@([A-Za-z0-9_]+)', 'gi');
          // let hashtag_regex = new RegExp('#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)', 'gi');

          let linkSearch = msg.match(link_regex)
          if (linkSearch && linkSearch.length > 0) {
              hasHTML = true;
              for (let linkSearchOne of linkSearch) {
                  let matchUrl = striptags(linkSearchOne)
                  let syntax = '[a]' + escape(matchUrl) + '[/a]'
                  message.text = message.text.replace(link_regex, syntax)
              }
          }
          let mentionSearch = msg.match(mention_regex)
          if (mentionSearch && mentionSearch.length > 0) {
              hasHTML = true;
              for (let mentionSearchOne of mentionSearch) {
                  let mention = await ctx.wo_users.findOne({
                      where: {
                          username: mentionSearchOne.substr(1, mentionSearchOne.length)
                      }
                  })
                  if (mention) {
                      let match_replace = '@[' + mention['user_id'] + ']';
                      message.text = message.text.replace(mention_regex, match_replace)
                  }
              }
          }
          let hashTagSearch = msg.match(/#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)/gi)
          if (hashTagSearch && hashTagSearch.length > 0) {
              hasHTML = true
              for (let hashTagSearchOne of hashTagSearch) {
                  let hashdata = await funcs.Wo_GetHashtag(ctx, hashTagSearchOne.substr(1))
                  let replaceString = '#[' + hashdata['id'] + ']';
                  message.text = message.text.replace(/#([^`~!@$%^&*\#()\-+=\\|\/\.,<>?\'\":;{}\[\]* ]+)/gi, replaceString)
                  await ctx.wo_hashtags.update({
                      last_trend_time: Math.floor(Date.now() / 1000),
                      trend_use_num: hashdata["trend_use_num"] + 1
                  },
                      {
                          where: {
                              id: hashdata['id']
                          }
                      })
              }
          }
          let sendable_message = await funcs.Wo_Markup(ctx, message.text);
          if (message.from_id === ctx.userHashUserId[data.from_id]) {
              html += await compiledTemplates.messageListOwnerTrue(ctx, data, fromUser, message, true, sendable_message, data.color)
          }
          else {
              html += await compiledTemplates.messageListOwnerFalse(ctx, data, message, fromUser, true, sendable_message)
          }
      }
  }
  callback({
      status: 200,
      html: html
  })
};

module.exports = { LoadmoreGroupPageController };